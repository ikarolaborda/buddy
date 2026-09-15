import { WorkflowEntrypoint, type WorkflowEvent, type WorkflowStep } from "cloudflare:workers";
import { azureClient, type TaskProgress } from "../azure";
import { flagEnabled, type EdgeEnv } from "../env";
import { budgetStub } from "../stubs";
import {
  canonicalRecoveryBody,
  decide,
  MAX_ITERATIONS,
  MAX_LIFETIME_MS,
  recoverRequestId,
  waitTimeoutMs,
} from "./decision";

/** The terminal envelope forwarded by the queue consumer; only the status is read here. */
export interface TerminalSignal {
  data?: { status?: string; failure_category?: string };
}

export interface SupervisorParams {
  task_id: string;
  client_id: string;
  generation: number;
  event_id: string;
  is_council?: boolean;
  is_recovery?: boolean;
}

export type DelegateStepResult =
  | { outcome: "delegated"; delegation: string; expires_at: string; generation: number }
  | { outcome: "not_eligible" }
  | { outcome: "budget_exhausted" };

export type StatusStepResult =
  | { outcome: "ok"; progress: TaskProgress }
  | { outcome: "gone" }
  | { outcome: "budget_exhausted" };

export interface SupervisorAudit {
  outcome: string;
  task_id: string;
  generation: number;
  iterations: number;
  notes: string[];
  final_status?: string;
  recovery?: "dispatched" | "completed" | "failed" | "blocked" | "running";
}

/**
 * One supervisor per task execution generation (`sup-${task_id}-${generation}`).
 * It waits for the terminal event, falls back to authoritative reads, and only ever asks Azure for
 * `diagnose_health` or exactly one `recover_evaluation`. It never runs a council and never forces a failure.
 */
export class BuddyTaskSupervisor extends WorkflowEntrypoint<EdgeEnv, SupervisorParams> {
  override async run(event: Readonly<WorkflowEvent<SupervisorParams>>, step: WorkflowStep): Promise<SupervisorAudit> {
    const params = event.payload;
    const audit: SupervisorAudit = { outcome: "running", task_id: params.task_id, generation: params.generation, iterations: 0, notes: [] };

    const delegate = await step.do<DelegateStepResult>(
      "delegate",
      { retries: { limit: 3, delay: "10 seconds", backoff: "exponential" }, timeout: "1 minute" },
      async () => {
        const budget = await budgetStub(this.env).increment("callbacks");
        if (!budget.allowed) return { outcome: "budget_exhausted" };
        const result = await azureClient(this.env).createDelegation(params.task_id, params.event_id, params.client_id);
        if (result.ok) {
          return { outcome: "delegated", delegation: result.data.delegation, expires_at: result.data.expires_at, generation: result.data.generation };
        }
        if (result.status === 404) return { outcome: "not_eligible" };
        throw new Error(`delegation_failed:${result.status}:${result.error}`);
      },
    );

    if (delegate.outcome !== "delegated") {
      audit.outcome = delegate.outcome;
      return audit;
    }
    const delegation = delegate.delegation;
    const startedAt = event.timestamp.getTime();
    const diagnosed: string[] = [];
    let recoveryRequested = false;
    let lastProgress: TaskProgress | null = null;

    for (let i = 0; i < MAX_ITERATIONS; i++) {
      audit.iterations = i + 1;
      const now = await step.do<number>(`clock-${i}`, async () => Date.now());
      if (now - startedAt > MAX_LIFETIME_MS) {
        audit.outcome = "lifetime_exceeded";
        return audit;
      }

      const timeout = waitTimeoutMs(lastProgress?.phase_deadline_at ?? null, now);
      let terminal: TerminalSignal | null = null;
      try {
        const received = await step.waitForEvent<TerminalSignal>(`terminal-${i}`, { type: "terminal", timeout });
        terminal = received.payload;
      } catch {
        // Timeout: fall through to an authoritative read.
      }
      if (terminal) {
        audit.outcome = "terminal";
        audit.final_status = typeof terminal.data?.status === "string" ? terminal.data.status : "unknown";
        return audit;
      }

      const status = await step.do<StatusStepResult>(
        `status-${i}`,
        { retries: { limit: 2, delay: "5 seconds", backoff: "constant" }, timeout: "1 minute" },
        async () => {
          const budget = await budgetStub(this.env).increment("callbacks");
          if (!budget.allowed) return { outcome: "budget_exhausted" };
          const result = await azureClient(this.env).status(params.task_id, delegation);
          if (result.ok) return { outcome: "ok", progress: result.data.progress };
          if (result.status === 404) return { outcome: "gone" };
          throw new Error(`status_failed:${result.status}:${result.error}`);
        },
      );
      if (status.outcome === "gone") {
        audit.outcome = "task_gone";
        return audit;
      }
      if (status.outcome === "budget_exhausted") {
        audit.notes.push(`iteration ${i}: status read skipped, callback budget exhausted`);
        continue;
      }
      lastProgress = status.progress;

      const decision = decide({
        now,
        task_id: params.task_id,
        generation: params.generation,
        progress: status.progress,
        is_council: params.is_council === true,
        is_recovery: params.is_recovery === true,
        auto_recovery_enabled: flagEnabled(this.env, "AUTO_RECOVERY_ENABLED"),
        diagnosed,
        recovery_requested: recoveryRequested,
      });

      if (decision.kind === "end") {
        audit.outcome = "terminal";
        audit.final_status = decision.outcome;
        return audit;
      }
      if (decision.kind === "stop") {
        audit.outcome = "stopped";
        audit.notes.push(decision.reason);
        audit.final_status = status.progress.status;
        return audit;
      }
      if (decision.kind === "wait") {
        audit.notes.push(`iteration ${i}: wait (${decision.reason})`);
        continue;
      }
      if (decision.kind === "diagnose") {
        diagnosed.push(decision.request_id);
        const outcome = await step.do<string>(`diagnose-${i}`, { retries: { limit: 2, delay: "5 seconds" }, timeout: "1 minute" }, async () => {
          const budget = await budgetStub(this.env).increment("callbacks");
          if (!budget.allowed) return "skipped:budget_exhausted";
          const result = await azureClient(this.env).intervene(params.task_id, {
            delegation,
            request_id: decision.request_id,
            action: "diagnose_health",
            blocker: "operational_failure",
            context: { summary: `Edge supervisor diagnosis (${decision.reason}).` },
          });
          if (result.ok) return `diagnosed:${result.data.status}`;
          if (result.status === 404 || result.status === 422) return `skipped:${result.error}`;
          throw new Error(`diagnose_failed:${result.status}:${result.error}`);
        });
        audit.notes.push(`iteration ${i}: ${outcome} (${decision.reason})`);
        continue;
      }

      // decision.kind === "recover": exactly once, canonical payload, then end.
      recoveryRequested = true;
      const recovery = await step.do<string>("recover", { retries: { limit: 3, delay: "10 seconds", backoff: "exponential" }, timeout: "1 minute" }, async () => {
        const budget = await budgetStub(this.env).increment("callbacks");
        if (!budget.allowed) return "skipped:budget_exhausted";
        const body = canonicalRecoveryBody(delegation, params.task_id, params.generation);
        const result = await azureClient(this.env).intervene(
          params.task_id,
          {
            delegation,
            request_id: recoverRequestId(params.task_id, params.generation),
            action: "recover_evaluation",
            blocker: "operational_failure",
            context: { summary: "" },
          },
          body,
        );
        if (result.ok) return result.data.status;
        if (result.status === 422) return `skipped:${result.error}`;
        if (result.status === 404) return "skipped:not_eligible";
        throw new Error(`recover_failed:${result.status}:${result.error}`);
      });
      if (recovery === "dispatched" || recovery === "completed" || recovery === "failed" || recovery === "blocked" || recovery === "running") {
        audit.recovery = recovery;
        audit.outcome = `recovery_${recovery}`;
      } else {
        audit.outcome = "recovery_skipped";
        audit.notes.push(recovery);
      }
      audit.final_status = status.progress.status;
      return audit;
    }

    audit.outcome = "iterations_exhausted";
    return audit;
  }
}
