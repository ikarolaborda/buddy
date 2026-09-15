import { DurableObject } from "cloudflare:workers";
import { intVar, type EdgeEnv } from "../env";

export type BudgetKind =
  | "events"
  | "callbacks"
  | "workflows"
  | "captures"
  | "sockets"
  | "cache_hits"
  | "cache_misses"
  | "reconcile_pending";

export const BUDGET_KINDS: readonly BudgetKind[] = [
  "events",
  "callbacks",
  "workflows",
  "captures",
  "sockets",
  "cache_hits",
  "cache_misses",
  "reconcile_pending",
];

export interface BudgetDecision {
  allowed: boolean;
  count: number;
  /** `null` for kinds that are counted but never limited. */
  limit: number | null;
  ratio: number;
  /** True only on the call that crossed the 80% line; the warning is logged once per UTC day and kind. */
  warning: boolean;
  day: string;
}

export interface ReconciliationItem {
  id: number;
  created_at: string;
  kind: string;
  task_id: string;
  generation: number;
  detail: string;
  resolved: boolean;
}

export interface BudgetReport {
  day: string;
  counters: Record<BudgetKind, { count: number; limit: number | null; ratio: number }>;
  active_captures: number;
  max_concurrent_captures: number;
  reconciliation_pending: number;
}

export const MAX_CONCURRENT_CAPTURES = 2;
const WARN_RATIO = 0.8;

interface CounterRow extends Record<string, SqlStorageValue> {
  count: number;
  warned: number;
}

interface GaugeRow extends Record<string, SqlStorageValue> {
  value: number;
}

interface ReconciliationRow extends Record<string, SqlStorageValue> {
  id: number;
  created_at: number;
  kind: string;
  task_id: string;
  generation: number;
  detail: string;
  resolved: number;
}

export function utcDay(at: number): string {
  return new Date(at).toISOString().slice(0, 10);
}

/**
 * Single global counter object (id `budget`). Counters are per UTC day and stored in SQLite so a
 * restart cannot reset them. Eventually consistent KV is deliberately not used for quotas.
 */
export class BuddyEdgeBudget extends DurableObject<EdgeEnv> {
  private readonly sql: SqlStorage;

  constructor(ctx: DurableObjectState, env: EdgeEnv) {
    super(ctx, env);
    this.sql = ctx.storage.sql;
    this.sql.exec(`CREATE TABLE IF NOT EXISTS counters (
      day TEXT NOT NULL,
      kind TEXT NOT NULL,
      count INTEGER NOT NULL DEFAULT 0,
      warned INTEGER NOT NULL DEFAULT 0,
      PRIMARY KEY (day, kind)
    )`);
    this.sql.exec(`CREATE TABLE IF NOT EXISTS reconciliation (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      created_at INTEGER NOT NULL,
      kind TEXT NOT NULL,
      task_id TEXT NOT NULL,
      generation INTEGER NOT NULL,
      detail TEXT NOT NULL,
      resolved INTEGER NOT NULL DEFAULT 0
    )`);
    this.sql.exec(`CREATE TABLE IF NOT EXISTS gauges (
      name TEXT PRIMARY KEY,
      value INTEGER NOT NULL
    )`);
  }

  limitFor(kind: BudgetKind): number | null {
    switch (kind) {
      case "events":
        return intVar(this.env.DAILY_BUDGET_EVENTS, 50_000);
      case "callbacks":
        return intVar(this.env.DAILY_BUDGET_CALLBACKS, 500);
      case "workflows":
        return intVar(this.env.DAILY_BUDGET_WORKFLOWS, 200);
      case "captures":
        return intVar(this.env.DAILY_BUDGET_CAPTURES, 10);
      default:
        return null;
    }
  }

  /**
   * Counts one unit of `kind` for the UTC day of `at` (defaults to now).
   * Denied when the counter has already reached its limit (ratio >= 1.0); a denied call does not count.
   */
  async increment(kind: BudgetKind, at: number = Date.now()): Promise<BudgetDecision> {
    if (!BUDGET_KINDS.includes(kind)) throw new Error(`unknown budget kind: ${kind}`);
    const day = utcDay(at);
    const limit = this.limitFor(kind);
    const row = this.sql.exec<CounterRow>("SELECT count, warned FROM counters WHERE day = ? AND kind = ?", day, kind).toArray()[0];
    const current = row?.count ?? 0;
    const warned = (row?.warned ?? 0) === 1;

    if (limit !== null && limit > 0 && current / limit >= 1.0) {
      return { allowed: false, count: current, limit, ratio: current / limit, warning: false, day };
    }
    if (limit === 0) {
      return { allowed: false, count: current, limit, ratio: 1, warning: false, day };
    }

    const next = current + 1;
    const ratio = limit === null ? 0 : next / limit;
    let warning = false;
    if (limit !== null && !warned && ratio >= WARN_RATIO) {
      warning = true;
      console.warn(`[buddy-edge] budget ${kind} at ${Math.round(ratio * 100)}% of ${limit} for ${day}`);
    }
    this.sql.exec(
      `INSERT INTO counters (day, kind, count, warned) VALUES (?, ?, ?, ?)
       ON CONFLICT (day, kind) DO UPDATE SET count = excluded.count, warned = MAX(counters.warned, excluded.warned)`,
      day,
      kind,
      next,
      warning || warned ? 1 : 0,
    );
    return { allowed: true, count: next, limit, ratio, warning, day };
  }

  async read(at: number = Date.now()): Promise<BudgetReport> {
    const day = utcDay(at);
    const counters = {} as BudgetReport["counters"];
    for (const kind of BUDGET_KINDS) {
      const row = this.sql.exec<CounterRow>("SELECT count, warned FROM counters WHERE day = ? AND kind = ?", day, kind).toArray()[0];
      const count = row?.count ?? 0;
      const limit = this.limitFor(kind);
      counters[kind] = { count, limit, ratio: limit === null || limit === 0 ? 0 : count / limit };
    }
    const pending = this.sql
      .exec<{ n: number }>("SELECT COUNT(*) AS n FROM reconciliation WHERE resolved = 0")
      .one().n;
    return {
      day,
      counters,
      active_captures: this.gauge("active_captures"),
      max_concurrent_captures: MAX_CONCURRENT_CAPTURES,
      reconciliation_pending: pending,
    };
  }

  /** Records work that could not be completed inline (for example a Workflow creation failure) for a later operator pass. */
  async recordReconciliation(item: { kind: string; task_id: string; generation: number; detail: string }, at: number = Date.now()): Promise<number> {
    this.sql.exec(
      "INSERT INTO reconciliation (created_at, kind, task_id, generation, detail) VALUES (?, ?, ?, ?, ?)",
      at,
      item.kind,
      item.task_id,
      item.generation,
      item.detail.slice(0, 500),
    );
    const id = this.sql.exec<{ id: number }>("SELECT last_insert_rowid() AS id").one().id;
    await this.increment("reconcile_pending", at);
    return id;
  }

  async listReconciliation(includeResolved = false): Promise<ReconciliationItem[]> {
    const rows = includeResolved
      ? this.sql.exec<ReconciliationRow>("SELECT * FROM reconciliation ORDER BY id DESC LIMIT 200").toArray()
      : this.sql.exec<ReconciliationRow>("SELECT * FROM reconciliation WHERE resolved = 0 ORDER BY id DESC LIMIT 200").toArray();
    return rows.map((row) => ({
      id: row.id,
      created_at: new Date(row.created_at).toISOString(),
      kind: row.kind,
      task_id: row.task_id,
      generation: row.generation,
      detail: row.detail,
      resolved: row.resolved === 1,
    }));
  }

  async resolveReconciliation(id: number): Promise<boolean> {
    const cursor = this.sql.exec("UPDATE reconciliation SET resolved = 1 WHERE id = ? AND resolved = 0", id);
    return cursor.rowsWritten > 0;
  }

  /** Global concurrency gate for browser captures (at most two sessions at a time). */
  async acquireCaptureSlot(): Promise<{ acquired: boolean; active: number; limit: number }> {
    const active = this.gauge("active_captures");
    if (active >= MAX_CONCURRENT_CAPTURES) return { acquired: false, active, limit: MAX_CONCURRENT_CAPTURES };
    this.setGauge("active_captures", active + 1);
    return { acquired: true, active: active + 1, limit: MAX_CONCURRENT_CAPTURES };
  }

  async releaseCaptureSlot(): Promise<number> {
    const active = Math.max(0, this.gauge("active_captures") - 1);
    this.setGauge("active_captures", active);
    return active;
  }

  private gauge(name: string): number {
    return this.sql.exec<GaugeRow>("SELECT value FROM gauges WHERE name = ?", name).toArray()[0]?.value ?? 0;
  }

  private setGauge(name: string, value: number): void {
    this.sql.exec("INSERT INTO gauges (name, value) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET value = excluded.value", name, value);
  }
}
