import { flagEnabled, type EdgeEnv } from "../env";
import { envelopeIsCouncil, envelopeIsRecoveryTask, isTerminalEnvelope, validateEnvelope, type EventEnvelope } from "../envelope";
import { budgetStub, progressStub } from "../stubs";
import type { SupervisorParams } from "../workflows/supervisor";

export type ConsumerKind = "events" | "artifacts";

export interface ConsumerSummary {
  acked: number;
  retried: number;
  supervisors_created: number;
  supervisors_deferred: number;
  terminal_signals: number;
}

export function supervisorInstanceId(taskId: string, generation: number): string {
  return `sup-${taskId}-${generation}`;
}

/** Workflow instance ids are unique; a second create for the same id is the idempotent success path. */
export function isAlreadyExistsError(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error);
  return /exist|conflict|duplicate|409/i.test(message);
}

/** 30 s per attempt, capped at 10 minutes; the platform moves the message to the DLQ after `max_retries`. */
export function retryDelaySeconds(attempts: number): number {
  return Math.min(30 * Math.max(1, attempts), 600);
}

/**
 * Consumes one queue batch. Each message is validated, routed to its task projection, and acknowledged only after
 * the Durable Object accepted it. Everything after the ack is best effort and can never block the ack.
 */
export async function consumeBatch(batch: MessageBatch<unknown>, env: EdgeEnv, kind: ConsumerKind): Promise<ConsumerSummary> {
  const summary: ConsumerSummary = { acked: 0, retried: 0, supervisors_created: 0, supervisors_deferred: 0, terminal_signals: 0 };
  const budget = budgetStub(env);

  for (const message of batch.messages) {
    const validation = validateEnvelope(message.body);
    if (!validation.ok) {
      // Unknown schema versions and malformed payloads travel the DLQ path: retry until max_retries, never crash.
      console.warn(`[buddy-edge] ${kind} message ${message.id} rejected: ${validation.reason}`);
      message.retry({ delaySeconds: retryDelaySeconds(message.attempts) });
      summary.retried += 1;
      continue;
    }
    const envelope = validation.envelope;

    let counted = true;
    try {
      const decision = await budget.increment("events");
      counted = decision.allowed;
    } catch (error) {
      console.warn(`[buddy-edge] budget unavailable, applying event anyway: ${String(error)}`);
    }
    if (!counted && !isTerminalEnvelope(envelope)) {
      // Terminal events keep an operating reserve; everything else waits for the next budget window.
      message.retry({ delaySeconds: 300 });
      summary.retried += 1;
      continue;
    }

    let result;
    try {
      result = await progressStub(env, envelope.client_id, envelope.task_id).applyEvent(envelope);
    } catch (error) {
      console.warn(`[buddy-edge] projection rejected ${envelope.event_id}: ${String(error)}`);
      message.retry({ delaySeconds: retryDelaySeconds(message.attempts) });
      summary.retried += 1;
      continue;
    }

    if (result.outcome === "rejected") {
      console.warn(`[buddy-edge] projection rejected ${envelope.event_id}: ${result.reason ?? "unknown"}`);
      message.retry({ delaySeconds: retryDelaySeconds(message.attempts) });
      summary.retried += 1;
      continue;
    }

    message.ack();
    summary.acked += 1;

    if (result.outcome !== "applied") continue;

    if (result.supervision && flagEnabled(env, "SUPERVISION_ENABLED")) {
      const created = await ensureSupervisor(env, envelope, result.supervision);
      if (created) summary.supervisors_created += 1;
      else summary.supervisors_deferred += 1;
    }

    if (result.terminal && flagEnabled(env, "SUPERVISION_ENABLED")) {
      try {
        const instance = await env.BUDDY_TASK_SUPERVISOR.get(supervisorInstanceId(envelope.task_id, envelope.generation));
        await instance.sendEvent({ type: "terminal", payload: envelope });
        summary.terminal_signals += 1;
      } catch (error) {
        // No supervisor for this generation, or it already finished. The authoritative read path covers it.
        console.info(`[buddy-edge] terminal signal skipped for ${envelope.task_id}: ${String(error)}`);
      }
    }
  }

  return summary;
}

async function ensureSupervisor(env: EdgeEnv, envelope: EventEnvelope, supervision: { generation: number; event_id: string }): Promise<boolean> {
  const budget = budgetStub(env);
  const projection = progressStub(env, envelope.client_id, envelope.task_id);
  const id = supervisorInstanceId(envelope.task_id, supervision.generation);

  try {
    const decision = await budget.increment("workflows");
    if (!decision.allowed) {
      await budget.recordReconciliation({
        kind: "workflow_budget_exhausted",
        task_id: envelope.task_id,
        generation: supervision.generation,
        detail: `supervisor ${id} not created: daily workflow budget exhausted (${decision.count}/${decision.limit})`,
      });
      await projection.markSupervisorFailed(supervision.generation);
      return false;
    }
  } catch (error) {
    console.warn(`[buddy-edge] budget unavailable for supervisor creation: ${String(error)}`);
  }

  const params: SupervisorParams = {
    task_id: envelope.task_id,
    client_id: envelope.client_id,
    generation: supervision.generation,
    event_id: supervision.event_id,
    is_council: envelopeIsCouncil(envelope),
    is_recovery: envelopeIsRecoveryTask(envelope),
  };

  try {
    await env.BUDDY_TASK_SUPERVISOR.create({ id, params });
  } catch (error) {
    if (!isAlreadyExistsError(error)) {
      try {
        await budget.recordReconciliation({
          kind: "workflow_create_failed",
          task_id: envelope.task_id,
          generation: supervision.generation,
          detail: `supervisor ${id} creation failed: ${error instanceof Error ? error.message : String(error)}`,
        });
        await projection.markSupervisorFailed(supervision.generation);
      } catch (inner) {
        console.warn(`[buddy-edge] could not record reconciliation item: ${String(inner)}`);
      }
      return false;
    }
  }

  try {
    await projection.markSupervisorCreated(supervision.generation);
  } catch (error) {
    console.warn(`[buddy-edge] supervisor ${id} created but projection not updated: ${String(error)}`);
  }
  return true;
}
