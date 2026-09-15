import { DurableObject } from "cloudflare:workers";
import type { TaskProgress } from "../azure";
import { intVar, type EdgeEnv } from "../env";
import { EVENT_TYPES, isProgressEnvelope, isTerminalEnvelope, validateEnvelope, type EventEnvelope } from "../envelope";
import { base64UrlEncode, sha256Hex } from "../http";

export const REPLAY_MAX_EVENTS = 100;
export const REPLAY_MAX_AGE_MS = 24 * 60 * 60 * 1000;
export const TICKET_TTL_MS = 60 * 1000;
export const HEARTBEAT_INTERVAL_MS = 30 * 1000;
export const TERMINAL_CLOSE_DELAY_MS = 5 * 60 * 1000;
export const CLOSE_CODE_TERMINAL = 4001;
export const CLOSE_CODE_POLICY = 4002;

export type ApplyOutcome = "applied" | "duplicate" | "gap" | "rejected";

export interface ApplyResult {
  outcome: ApplyOutcome;
  /** Set only for `rejected`. */
  reason?: string;
  last_sequence: number;
  gap: boolean;
  terminal: boolean;
  /** Set when the consumer should (re)try creating the supervisor for this generation. */
  supervision: { generation: number; event_id: string } | null;
}

export interface ReconcileInput {
  progress?: TaskProgress | null;
  events: unknown[];
}

export interface ReconcileResult {
  last_sequence: number;
  gap: boolean;
  applied: number;
  ignored: number;
}

export interface ProjectionState {
  last_sequence: number;
  gap: boolean;
  progress: TaskProgress | null;
  buffer_size: number;
  buffer_min_sequence: number | null;
  pending_sequences: number[];
  sockets: number;
  close_at: string | null;
  supervision: { generation: number; status: string; attempts: number }[];
}

export interface SocketAttachment {
  client_id: string;
  task_id: string;
  connected_at: number;
}

interface MetaRow extends Record<string, SqlStorageValue> {
  last_sequence: number;
  snapshot_json: string | null;
  updated_at: number;
  gap: number;
  close_at: number | null;
}

interface EventRow extends Record<string, SqlStorageValue> {
  sequence: number;
  envelope_json: string;
  received_at: number;
}

interface TicketRow extends Record<string, SqlStorageValue> {
  client_id: string;
  task_id: string;
}

interface SupervisionRow extends Record<string, SqlStorageValue> {
  generation: number;
  status: string;
  attempts: number;
}

type ServerMessage =
  | { type: "hello"; last_sequence: number; gap: boolean; progress: TaskProgress | null }
  | { type: "event"; envelope: EventEnvelope }
  | { type: "snapshot"; progress: TaskProgress | null; last_sequence: number }
  | { type: "snapshot_required"; last_sequence: number }
  | { type: "resume_ok"; last_sequence: number; events: EventEnvelope[] }
  | { type: "ping"; at: number }
  | { type: "pong"; at: number }
  | { type: "error"; error: string };

/**
 * One projection per `${client_id}:${task_id}`. Holds the last applied sequence, a bounded replay buffer
 * (newest 100 events, 24 hours), a pending set for out-of-order arrivals, single-use WebSocket tickets and
 * the hibernating sockets of connected dashboards. The authoritative state always lives in Azure.
 */
export class BuddyTaskProgress extends DurableObject<EdgeEnv> {
  private readonly sql: SqlStorage;

  constructor(ctx: DurableObjectState, env: EdgeEnv) {
    super(ctx, env);
    this.sql = ctx.storage.sql;
    this.sql.exec(`CREATE TABLE IF NOT EXISTS meta (
      id INTEGER PRIMARY KEY CHECK (id = 1),
      last_sequence INTEGER NOT NULL DEFAULT 0,
      snapshot_json TEXT,
      updated_at INTEGER NOT NULL DEFAULT 0,
      gap INTEGER NOT NULL DEFAULT 0,
      close_at INTEGER
    )`);
    this.sql.exec("INSERT OR IGNORE INTO meta (id) VALUES (1)");
    this.sql.exec(`CREATE TABLE IF NOT EXISTS events (
      sequence INTEGER PRIMARY KEY,
      envelope_json TEXT NOT NULL,
      received_at INTEGER NOT NULL
    )`);
    this.sql.exec(`CREATE TABLE IF NOT EXISTS pending_events (
      sequence INTEGER PRIMARY KEY,
      envelope_json TEXT NOT NULL,
      received_at INTEGER NOT NULL
    )`);
    this.sql.exec(`CREATE TABLE IF NOT EXISTS tickets (
      ticket_hash TEXT PRIMARY KEY,
      client_id TEXT NOT NULL,
      task_id TEXT NOT NULL,
      expires_at INTEGER NOT NULL,
      used INTEGER NOT NULL DEFAULT 0
    )`);
    this.sql.exec(`CREATE TABLE IF NOT EXISTS supervision (
      generation INTEGER PRIMARY KEY,
      status TEXT NOT NULL,
      event_id TEXT NOT NULL,
      attempts INTEGER NOT NULL DEFAULT 0,
      updated_at INTEGER NOT NULL
    )`);
    // Clients may send "ping"; answer without waking the object.
    ctx.setWebSocketAutoResponse(new WebSocketRequestResponsePair('{"type":"ping"}', '{"type":"pong"}'));
  }

  // ---------------------------------------------------------------------------------------------
  // Event ingestion
  // ---------------------------------------------------------------------------------------------

  /** Applies one envelope in order. Old sequences are ignored, future sequences are parked and flagged as a gap. */
  async applyEvent(input: unknown): Promise<ApplyResult> {
    const validation = validateEnvelope(input);
    const now = Date.now();
    const meta = this.meta();
    if (!validation.ok) {
      return { outcome: "rejected", reason: validation.reason, last_sequence: meta.last_sequence, gap: meta.gap === 1, terminal: false, supervision: null };
    }
    const envelope = validation.envelope;

    if (envelope.task_sequence <= meta.last_sequence) {
      return { outcome: "duplicate", last_sequence: meta.last_sequence, gap: meta.gap === 1, terminal: false, supervision: null };
    }

    if (envelope.task_sequence === meta.last_sequence + 1) {
      const applied = this.commit(envelope, now);
      const drained = this.drainPending(now);
      const all = [applied, ...drained];
      const state = this.meta();
      const terminal = all.some(isTerminalEnvelope);
      if (terminal) await this.scheduleTerminalClose(now);
      else await this.ensureAlarm(now);
      return {
        outcome: "applied",
        last_sequence: state.last_sequence,
        gap: state.gap === 1,
        terminal,
        supervision: this.supervisionFor(all, now),
      };
    }

    this.sql.exec(
      "INSERT OR IGNORE INTO pending_events (sequence, envelope_json, received_at) VALUES (?, ?, ?)",
      envelope.task_sequence,
      JSON.stringify(envelope),
      now,
    );
    this.sql.exec("UPDATE meta SET gap = 1, updated_at = ? WHERE id = 1", now);
    this.broadcast({ type: "snapshot_required", last_sequence: meta.last_sequence });
    return { outcome: "gap", last_sequence: meta.last_sequence, gap: true, terminal: false, supervision: null };
  }

  /**
   * Fills gaps from an authorized Azure snapshot. Events are applied in order; an authoritative
   * `progress_sequence` beyond the buffer fast-forwards the projection so a truncated history cannot pin it in a gap.
   */
  async reconcile(input: ReconcileInput): Promise<ReconcileResult> {
    const now = Date.now();
    const envelopes: EventEnvelope[] = [];
    for (const raw of input.events ?? []) {
      const validation = validateEnvelope(raw);
      if (validation.ok) envelopes.push(validation.envelope);
    }
    envelopes.sort((a, b) => a.task_sequence - b.task_sequence);

    let applied = 0;
    let ignored = 0;
    let terminal = false;
    for (const envelope of envelopes) {
      const meta = this.meta();
      if (envelope.task_sequence <= meta.last_sequence) {
        ignored += 1;
        continue;
      }
      if (envelope.task_sequence === meta.last_sequence + 1) {
        this.commit(envelope, now);
        applied += 1;
        terminal = terminal || isTerminalEnvelope(envelope);
        for (const drained of this.drainPending(now)) {
          applied += 1;
          terminal = terminal || isTerminalEnvelope(drained);
        }
        continue;
      }
      this.sql.exec(
        "INSERT OR IGNORE INTO pending_events (sequence, envelope_json, received_at) VALUES (?, ?, ?)",
        envelope.task_sequence,
        JSON.stringify(envelope),
        now,
      );
    }

    if (input.progress) {
      const authoritative = typeof input.progress.progress_sequence === "number" ? input.progress.progress_sequence : 0;
      const meta = this.meta();
      if (authoritative > meta.last_sequence) {
        this.sql.exec("DELETE FROM pending_events WHERE sequence <= ?", authoritative);
        this.sql.exec("UPDATE meta SET last_sequence = ?, updated_at = ? WHERE id = 1", authoritative, now);
        for (const drained of this.drainPending(now)) {
          applied += 1;
          terminal = terminal || isTerminalEnvelope(drained);
        }
      }
      this.sql.exec("UPDATE meta SET snapshot_json = ?, updated_at = ? WHERE id = 1", JSON.stringify(input.progress), now);
      const after = this.meta();
      this.broadcast({ type: "snapshot", progress: input.progress, last_sequence: after.last_sequence });
      if (input.progress.status === "completed" || input.progress.status === "failed" || input.progress.status === "closed") {
        terminal = true;
      }
    }

    const pendingLeft = this.sql.exec<{ n: number }>("SELECT COUNT(*) AS n FROM pending_events").one().n;
    this.sql.exec("UPDATE meta SET gap = ?, updated_at = ? WHERE id = 1", pendingLeft > 0 ? 1 : 0, now);
    this.prune(now);
    if (terminal) await this.scheduleTerminalClose(now);
    const meta = this.meta();
    return { last_sequence: meta.last_sequence, gap: meta.gap === 1, applied, ignored };
  }

  async markSupervisorCreated(generation: number): Promise<void> {
    this.sql.exec("UPDATE supervision SET status = 'created', updated_at = ? WHERE generation = ?", Date.now(), generation);
  }

  async markSupervisorFailed(generation: number): Promise<void> {
    this.sql.exec("UPDATE supervision SET attempts = attempts + 1, updated_at = ? WHERE generation = ?", Date.now(), generation);
  }

  async getState(): Promise<ProjectionState> {
    const meta = this.meta();
    const buffer = this.sql.exec<{ n: number; min_seq: number | null }>("SELECT COUNT(*) AS n, MIN(sequence) AS min_seq FROM events").one();
    const pending = this.sql.exec<{ sequence: number }>("SELECT sequence FROM pending_events ORDER BY sequence").toArray();
    const supervision = this.sql.exec<SupervisionRow>("SELECT generation, status, attempts FROM supervision ORDER BY generation").toArray();
    return {
      last_sequence: meta.last_sequence,
      gap: meta.gap === 1,
      progress: meta.snapshot_json ? (JSON.parse(meta.snapshot_json) as TaskProgress) : null,
      buffer_size: buffer.n,
      buffer_min_sequence: buffer.min_seq,
      pending_sequences: pending.map((row) => row.sequence),
      sockets: this.ctx.getWebSockets().length,
      close_at: meta.close_at ? new Date(meta.close_at).toISOString() : null,
      supervision: supervision.map((row) => ({ generation: row.generation, status: row.status, attempts: row.attempts })),
    };
  }

  /** Returns the buffered events after `afterSequence`, or `null` when the buffer no longer covers that point. */
  async eventsAfter(afterSequence: number): Promise<EventEnvelope[] | null> {
    const meta = this.meta();
    if (afterSequence >= meta.last_sequence) return [];
    const rows = this.sql
      .exec<EventRow>("SELECT sequence, envelope_json, received_at FROM events WHERE sequence > ? ORDER BY sequence", afterSequence)
      .toArray();
    if (rows.length === 0 || rows[0]!.sequence !== afterSequence + 1) return null;
    return rows.map((row) => JSON.parse(row.envelope_json) as EventEnvelope);
  }

  // ---------------------------------------------------------------------------------------------
  // WebSocket tickets and connections
  // ---------------------------------------------------------------------------------------------

  /** Mints a single-use ticket valid for 60 seconds, bound to the session's client and task. Only the hash is stored. */
  async mintTicket(clientId: string, taskId: string): Promise<{ ticket: string; expires_at: string }> {
    const now = Date.now();
    this.sql.exec("DELETE FROM tickets WHERE expires_at <= ? OR used = 1", now);
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    const ticket = base64UrlEncode(bytes);
    const expiresAt = now + TICKET_TTL_MS;
    this.sql.exec(
      "INSERT INTO tickets (ticket_hash, client_id, task_id, expires_at, used) VALUES (?, ?, ?, ?, 0)",
      await sha256Hex(ticket),
      clientId,
      taskId,
      expiresAt,
    );
    return { ticket, expires_at: new Date(expiresAt).toISOString() };
  }

  /** Consumes a ticket exactly once. Returns the bound scope, or `null` when the ticket is unknown, used, or expired. */
  async consumeTicket(ticket: string): Promise<{ client_id: string; task_id: string } | null> {
    const now = Date.now();
    const hash = await sha256Hex(ticket);
    const row = this.sql.exec<TicketRow>("SELECT client_id, task_id FROM tickets WHERE ticket_hash = ? AND used = 0 AND expires_at > ?", hash, now).toArray()[0];
    if (!row) return null;
    const update = this.sql.exec("UPDATE tickets SET used = 1 WHERE ticket_hash = ? AND used = 0 AND expires_at > ?", hash, now);
    if (update.rowsWritten === 0) return null;
    return { client_id: row.client_id, task_id: row.task_id };
  }

  override async fetch(request: Request): Promise<Response> {
    const url = new URL(request.url);
    if (request.headers.get("Upgrade")?.toLowerCase() !== "websocket") {
      return Response.json({ error: "websocket_required" }, { status: 426 });
    }
    const ticket = url.searchParams.get("ticket");
    if (!ticket) return Response.json({ error: "ticket_required" }, { status: 400 });
    const scope = await this.consumeTicket(ticket);
    if (!scope) return Response.json({ error: "ticket_invalid" }, { status: 401 });

    const maxSockets = intVar(this.env.MAX_ACTIVE_SOCKETS_PER_TASK, 20);
    if (this.ctx.getWebSockets().length >= maxSockets) {
      return Response.json({ error: "too_many_sockets" }, { status: 429 });
    }

    const pair = new WebSocketPair();
    const client = pair[0];
    const server = pair[1];
    this.ctx.acceptWebSocket(server);
    const attachment: SocketAttachment = { client_id: scope.client_id, task_id: scope.task_id, connected_at: Date.now() };
    server.serializeAttachment(attachment);
    const meta = this.meta();
    this.send(server, {
      type: "hello",
      last_sequence: meta.last_sequence,
      gap: meta.gap === 1,
      progress: meta.snapshot_json ? (JSON.parse(meta.snapshot_json) as TaskProgress) : null,
    });
    await this.ensureAlarm(Date.now());
    return new Response(null, { status: 101, webSocket: client });
  }

  override async webSocketMessage(ws: WebSocket, message: string | ArrayBuffer): Promise<void> {
    if (typeof message !== "string" || message.length > 4096) {
      this.send(ws, { type: "error", error: "unsupported_message" });
      return;
    }
    let parsed: { type?: unknown; after_sequence?: unknown };
    try {
      parsed = JSON.parse(message) as { type?: unknown; after_sequence?: unknown };
    } catch {
      this.send(ws, { type: "error", error: "invalid_json" });
      return;
    }
    if (parsed.type === "resume") {
      const after = typeof parsed.after_sequence === "number" && Number.isInteger(parsed.after_sequence) && parsed.after_sequence >= 0
        ? parsed.after_sequence
        : 0;
      const meta = this.meta();
      const events = await this.eventsAfter(after);
      if (events === null) {
        this.send(ws, { type: "snapshot_required", last_sequence: meta.last_sequence });
      } else {
        this.send(ws, { type: "resume_ok", last_sequence: meta.last_sequence, events });
      }
      return;
    }
    if (parsed.type === "ping") {
      this.send(ws, { type: "pong", at: Date.now() });
      return;
    }
    if (parsed.type === "pong") return;
    this.send(ws, { type: "error", error: "unknown_message_type" });
  }

  override async webSocketClose(ws: WebSocket, code: number, reason: string, wasClean: boolean): Promise<void> {
    try {
      ws.close(code, reason);
    } catch {
      // Already closed.
    }
    void wasClean;
  }

  override async webSocketError(ws: WebSocket): Promise<void> {
    try {
      ws.close(1011, "socket_error");
    } catch {
      // Already closed.
    }
  }

  override async alarm(): Promise<void> {
    const now = Date.now();
    const meta = this.meta();
    this.sql.exec("DELETE FROM tickets WHERE expires_at <= ? OR used = 1", now);

    if (meta.close_at !== null && now >= meta.close_at) {
      for (const ws of this.ctx.getWebSockets()) {
        try {
          ws.close(CLOSE_CODE_TERMINAL, "task_terminal");
        } catch {
          // Ignore sockets that are already gone.
        }
      }
      this.sql.exec("UPDATE meta SET close_at = NULL, updated_at = ? WHERE id = 1", now);
      return;
    }

    const sockets = this.ctx.getWebSockets();
    if (sockets.length > 0) this.broadcast({ type: "ping", at: now });
    if (sockets.length > 0 || meta.close_at !== null) {
      const next = Math.min(now + HEARTBEAT_INTERVAL_MS, meta.close_at ?? Number.POSITIVE_INFINITY);
      await this.ctx.storage.setAlarm(next);
    }
  }

  // ---------------------------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------------------------

  private meta(): MetaRow {
    return this.sql.exec<MetaRow>("SELECT last_sequence, snapshot_json, updated_at, gap, close_at FROM meta WHERE id = 1").one();
  }

  private commit(envelope: EventEnvelope, now: number): EventEnvelope {
    this.sql.exec(
      "INSERT OR REPLACE INTO events (sequence, envelope_json, received_at) VALUES (?, ?, ?)",
      envelope.task_sequence,
      JSON.stringify(envelope),
      now,
    );
    this.sql.exec("DELETE FROM pending_events WHERE sequence <= ?", envelope.task_sequence);
    this.sql.exec("UPDATE meta SET last_sequence = ?, updated_at = ? WHERE id = 1", envelope.task_sequence, now);
    this.updateSnapshot(envelope, now);
    this.prune(now);
    this.broadcast({ type: "event", envelope });
    return envelope;
  }

  private drainPending(now: number): EventEnvelope[] {
    const drained: EventEnvelope[] = [];
    for (;;) {
      const meta = this.meta();
      const next = this.sql
        .exec<EventRow>("SELECT sequence, envelope_json, received_at FROM pending_events WHERE sequence = ?", meta.last_sequence + 1)
        .toArray()[0];
      if (!next) break;
      const envelope = JSON.parse(next.envelope_json) as EventEnvelope;
      this.commit(envelope, now);
      drained.push(envelope);
    }
    const pendingLeft = this.sql.exec<{ n: number }>("SELECT COUNT(*) AS n FROM pending_events").one().n;
    this.sql.exec("UPDATE meta SET gap = ?, updated_at = ? WHERE id = 1", pendingLeft > 0 ? 1 : 0, now);
    return drained;
  }

  private prune(now: number): void {
    const meta = this.meta();
    this.sql.exec("DELETE FROM events WHERE sequence <= ?", meta.last_sequence - REPLAY_MAX_EVENTS);
    this.sql.exec("DELETE FROM events WHERE received_at < ?", now - REPLAY_MAX_AGE_MS);
    this.sql.exec("DELETE FROM pending_events WHERE received_at < ?", now - REPLAY_MAX_AGE_MS);
  }

  /** Keeps a minimal progress snapshot from the events themselves so a fresh socket can render before Azure answers. */
  private updateSnapshot(envelope: EventEnvelope, now: number): void {
    const meta = this.meta();
    const current: TaskProgress = meta.snapshot_json ? (JSON.parse(meta.snapshot_json) as TaskProgress) : { status: "unknown" };
    const data = envelope.data;
    const next: TaskProgress = { ...current, progress_sequence: envelope.task_sequence, generation: envelope.generation, state_version: envelope.state_version };
    if (envelope.type === EVENT_TYPES.progress) {
      if (typeof data.status === "string") next.status = data.status;
      else if (next.status === "unknown") next.status = "evaluating";
      for (const key of ["phase", "phase_started_at", "phase_deadline_at", "queued_at", "worker_started_at", "heartbeat_at"] as const) {
        if (typeof data[key] === "string") next[key] = data[key] as string;
      }
      if (typeof data.next_poll_after_ms === "number") next.next_poll_after_ms = data.next_poll_after_ms;
    } else if (envelope.type === EVENT_TYPES.terminal) {
      if (typeof data.status === "string") next.status = data.status;
      if (typeof data.failure_category === "string") next.failure_category = data.failure_category;
    } else if (envelope.type === EVENT_TYPES.recovery) {
      if (typeof data.recovery_task_id === "string") next.recovery_task_id = data.recovery_task_id;
    }
    this.sql.exec("UPDATE meta SET snapshot_json = ?, updated_at = ? WHERE id = 1", JSON.stringify(next), now);
  }

  private supervisionFor(applied: EventEnvelope[], now: number): ApplyResult["supervision"] {
    let needed: ApplyResult["supervision"] = null;
    for (const envelope of applied) {
      const row = this.sql
        .exec<SupervisionRow & { event_id: string }>("SELECT generation, status, attempts, event_id FROM supervision WHERE generation = ?", envelope.generation)
        .toArray()[0];
      if (!row) {
        if (!isProgressEnvelope(envelope)) continue;
        this.sql.exec(
          "INSERT INTO supervision (generation, status, event_id, attempts, updated_at) VALUES (?, 'pending', ?, 0, ?)",
          envelope.generation,
          envelope.event_id,
          now,
        );
        needed = { generation: envelope.generation, event_id: envelope.event_id };
      } else if (row.status === "pending") {
        needed = { generation: row.generation, event_id: row.event_id };
      }
    }
    return needed;
  }

  private send(ws: WebSocket, message: ServerMessage): void {
    try {
      ws.send(JSON.stringify(message));
    } catch {
      try {
        ws.close(1011, "send_failed");
      } catch {
        // Ignore.
      }
    }
  }

  private broadcast(message: ServerMessage): void {
    const payload = JSON.stringify(message);
    for (const ws of this.ctx.getWebSockets()) {
      try {
        ws.send(payload);
      } catch {
        try {
          ws.close(1011, "send_failed");
        } catch {
          // Ignore.
        }
      }
    }
  }

  private async ensureAlarm(now: number): Promise<void> {
    const existing = await this.ctx.storage.getAlarm();
    if (existing === null && this.ctx.getWebSockets().length > 0) {
      await this.ctx.storage.setAlarm(now + HEARTBEAT_INTERVAL_MS);
    }
  }

  private async scheduleTerminalClose(now: number): Promise<void> {
    const closeAt = now + TERMINAL_CLOSE_DELAY_MS;
    this.sql.exec("UPDATE meta SET close_at = ?, updated_at = ? WHERE id = 1", closeAt, now);
    const existing = await this.ctx.storage.getAlarm();
    const next = existing === null ? Math.min(now + HEARTBEAT_INTERVAL_MS, closeAt) : Math.min(existing, closeAt);
    await this.ctx.storage.setAlarm(next);
  }
}
