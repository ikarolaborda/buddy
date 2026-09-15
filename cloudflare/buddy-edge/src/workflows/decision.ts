import type { TaskProgress } from "../azure";

export const QUEUE_DIAGNOSE_AFTER_MS = 5 * 60 * 1000;
export const HEARTBEAT_STALE_AFTER_MS = 20 * 60 * 1000;
export const DIAGNOSE_WINDOW_MS = 10 * 60 * 1000;
export const MIN_WAIT_MS = 60 * 1000;
export const MAX_WAIT_MS = 10 * 60 * 1000;
export const MAX_LIFETIME_MS = 2 * 60 * 60 * 1000;
export const MAX_ITERATIONS = 40;

export const RECOVERY_SUMMARY = "Edge supervisor observed a transient evaluation failure and requests one bounded recovery.";

export interface DecisionInput {
  now: number;
  task_id: string;
  generation: number;
  progress: TaskProgress;
  is_council: boolean;
  is_recovery: boolean;
  auto_recovery_enabled: boolean;
  /** Request ids of diagnoses already sent by this supervisor. */
  diagnosed: readonly string[];
  recovery_requested: boolean;
}

export type Decision =
  | { kind: "end"; outcome: "completed" | "closed" }
  | { kind: "stop"; reason: string }
  | { kind: "wait"; reason: string; timeout_ms: number }
  | { kind: "diagnose"; request_id: string; reason: string }
  | { kind: "recover"; request_id: string };

function parseTime(value: string | null | undefined): number | null {
  if (!value) return null;
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? null : parsed;
}

/** Wait until the recorded phase deadline, clamped to [60 s, 10 min]; without a deadline wait the maximum. */
export function waitTimeoutMs(phaseDeadlineAt: string | null | undefined, now: number): number {
  const deadline = parseTime(phaseDeadlineAt);
  const raw = deadline === null ? MAX_WAIT_MS : deadline - now;
  return Math.min(MAX_WAIT_MS, Math.max(MIN_WAIT_MS, raw));
}

export function diagnoseRequestId(taskId: string, generation: number, now: number): string {
  return `sup:${taskId}:${generation}:diag:${Math.floor(now / DIAGNOSE_WINDOW_MS)}`;
}

export function recoverRequestId(taskId: string, generation: number): string {
  return `sup:${taskId}:${generation}:recover:v1`;
}

/**
 * Canonical recovery request body. Byte-identical on every retry: fixed key order, fixed summary, no timestamps.
 */
export function canonicalRecoveryBody(delegation: string, taskId: string, generation: number): string {
  return JSON.stringify({
    delegation,
    request_id: recoverRequestId(taskId, generation),
    action: "recover_evaluation",
    blocker: "operational_failure",
    context: { summary: RECOVERY_SUMMARY },
  });
}

function isCouncilPhase(progress: TaskProgress): boolean {
  return typeof progress.phase === "string" && progress.phase.startsWith("council.");
}

/** Pure decision table from the plan (section 8). No I/O, fully unit-testable. */
export function decide(input: DecisionInput): Decision {
  const { progress, now } = input;
  const status = progress.status;

  if (status === "completed" || status === "closed") {
    return { kind: "end", outcome: status };
  }

  if (status === "failed") {
    if (input.is_recovery) return { kind: "stop", reason: "recovery_task_failed" };
    if (input.is_council || isCouncilPhase(progress)) return { kind: "stop", reason: "council_failure" };
    const category = progress.failure_category ?? "unknown";
    if (category !== "transient") return { kind: "stop", reason: `failure_${category}` };
    if (!input.auto_recovery_enabled) return { kind: "stop", reason: "auto_recovery_disabled" };
    if (input.recovery_requested) return { kind: "stop", reason: "recovery_already_requested" };
    return { kind: "recover", request_id: recoverRequestId(input.task_id, input.generation) };
  }

  const workerStartedAt = parseTime(progress.worker_started_at);
  const heartbeatAt = parseTime(progress.heartbeat_at);
  const deadlineAt = parseTime(progress.phase_deadline_at);
  const diagId = diagnoseRequestId(input.task_id, input.generation, now);

  if (workerStartedAt === null) {
    const queuedAt = parseTime(progress.queued_at);
    const queueAge = queuedAt === null ? 0 : now - queuedAt;
    if (queueAge > QUEUE_DIAGNOSE_AFTER_MS && !input.diagnosed.includes(diagId)) {
      return { kind: "diagnose", request_id: diagId, reason: "queued_too_long" };
    }
    return { kind: "wait", reason: "queued", timeout_ms: waitTimeoutMs(progress.phase_deadline_at, now) };
  }

  if (deadlineAt !== null && deadlineAt > now) {
    return { kind: "wait", reason: "live_claim", timeout_ms: waitTimeoutMs(progress.phase_deadline_at, now) };
  }

  const heartbeatAge = heartbeatAt === null ? now - workerStartedAt : now - heartbeatAt;
  if (heartbeatAge > HEARTBEAT_STALE_AFTER_MS && !input.diagnosed.includes(diagId)) {
    return { kind: "diagnose", request_id: diagId, reason: "heartbeat_stale" };
  }

  return { kind: "wait", reason: "active_phase", timeout_ms: waitTimeoutMs(progress.phase_deadline_at, now) };
}
