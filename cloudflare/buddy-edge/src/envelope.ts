/**
 * Event envelope contract (schema_version 1). Azure publishes `{ "body": envelope }` through the Queues REST API,
 * so the queue message body is the envelope itself.
 */
export const SUPPORTED_SCHEMA_VERSION = 1;

export const EVENT_TYPES = {
  progress: "buddy.task.progress.v1",
  terminal: "buddy.task.terminal.v1",
  recovery: "buddy.task.recovery.v1",
  artifactAvailable: "buddy.task.artifact.available.v1",
  artifactProcessed: "buddy.task.artifact.processed.v1",
  exportCompleted: "buddy.task.export.completed.v1",
} as const;

export type EventType = (typeof EVENT_TYPES)[keyof typeof EVENT_TYPES];

export const PROGRESS_PHASES = [
  "queued",
  "memory",
  "evaluation",
  "council.frame",
  "council.positions",
  "council.attacks",
  "council.verdict",
] as const;

export const TERMINAL_STATUSES = ["completed", "failed", "closed"] as const;

export type JsonPrimitive = string | number | boolean | null;
export type JsonRecord = { [key: string]: JsonPrimitive | JsonPrimitive[] };
/** Event `data` is JSON with at most two levels (for example a council roster: an array of role/model records). */
export type JsonObject = { [key: string]: JsonPrimitive | JsonPrimitive[] | JsonRecord | JsonRecord[] };

export interface EventEnvelope {
  schema_version: 1;
  event_id: string;
  type: string;
  client_id: string;
  task_id: string;
  task_sequence: number;
  state_version: number;
  generation: number;
  occurred_at: string;
  trace_id: string;
  data: JsonObject;
}

export type EnvelopeValidation =
  | { ok: true; envelope: EventEnvelope }
  | { ok: false; reason: string; unsupported_schema: boolean };

function isNonEmptyString(value: unknown): value is string {
  return typeof value === "string" && value.length > 0;
}

function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isInteger(value) && value >= 0;
}

/** Manual, dependency-free validation. Unknown schema versions are flagged so the consumer can route them to the DLQ path. */
export function validateEnvelope(input: unknown): EnvelopeValidation {
  if (input === null || typeof input !== "object" || Array.isArray(input)) {
    return { ok: false, reason: "envelope_not_object", unsupported_schema: false };
  }
  const candidate = input as Record<string, unknown>;
  if (candidate.schema_version !== SUPPORTED_SCHEMA_VERSION) {
    return { ok: false, reason: "unsupported_schema_version", unsupported_schema: true };
  }
  for (const field of ["event_id", "type", "client_id", "task_id", "occurred_at", "trace_id"] as const) {
    if (!isNonEmptyString(candidate[field])) {
      return { ok: false, reason: `missing_${field}`, unsupported_schema: false };
    }
  }
  if (!(candidate.type as string).startsWith("buddy.task.")) {
    return { ok: false, reason: "unknown_type", unsupported_schema: false };
  }
  if (!isNonNegativeInteger(candidate.task_sequence) || candidate.task_sequence < 1) {
    return { ok: false, reason: "invalid_task_sequence", unsupported_schema: false };
  }
  if (!isNonNegativeInteger(candidate.state_version)) {
    return { ok: false, reason: "invalid_state_version", unsupported_schema: false };
  }
  const generation = candidate.generation === undefined ? 0 : candidate.generation;
  if (!isNonNegativeInteger(generation)) {
    return { ok: false, reason: "invalid_generation", unsupported_schema: false };
  }
  if (Number.isNaN(Date.parse(candidate.occurred_at as string))) {
    return { ok: false, reason: "invalid_occurred_at", unsupported_schema: false };
  }
  const data = candidate.data ?? {};
  if (data === null || typeof data !== "object" || Array.isArray(data)) {
    return { ok: false, reason: "invalid_data", unsupported_schema: false };
  }
  return {
    ok: true,
    envelope: {
      schema_version: 1,
      event_id: candidate.event_id as string,
      type: candidate.type as string,
      client_id: candidate.client_id as string,
      task_id: candidate.task_id as string,
      task_sequence: candidate.task_sequence,
      state_version: candidate.state_version,
      generation,
      occurred_at: candidate.occurred_at as string,
      trace_id: candidate.trace_id as string,
      data: data as JsonObject,
    },
  };
}

export function isTerminalEnvelope(envelope: EventEnvelope): boolean {
  return envelope.type === EVENT_TYPES.terminal;
}

export function isProgressEnvelope(envelope: EventEnvelope): boolean {
  return envelope.type === EVENT_TYPES.progress;
}

/** Artifact events travel the isolated artifacts queue. */
export function isArtifactEnvelope(envelope: EventEnvelope): boolean {
  return envelope.type.startsWith("buddy.task.artifact.");
}

export function envelopePhase(envelope: EventEnvelope): string | null {
  const phase = envelope.data.phase;
  return typeof phase === "string" ? phase : null;
}

export function envelopeIsCouncil(envelope: EventEnvelope): boolean {
  const phase = envelopePhase(envelope);
  if (phase !== null && phase.startsWith("council.")) return true;
  return envelope.data.operation === "council";
}

/** A task that is itself a recovery child must never spawn another recovery. */
export function envelopeIsRecoveryTask(envelope: EventEnvelope): boolean {
  const data = envelope.data;
  return (
    data.is_recovery === true ||
    typeof data.recovery_of_task_id === "string" ||
    typeof data.parent_task_id === "string" ||
    typeof data.recovered_from_task_id === "string"
  );
}
