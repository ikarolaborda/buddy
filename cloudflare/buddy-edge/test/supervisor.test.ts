import { introspectWorkflowInstance } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import {
  canonicalRecoveryBody,
  decide,
  diagnoseRequestId,
  MAX_WAIT_MS,
  MIN_WAIT_MS,
  waitTimeoutMs,
  type DecisionInput,
} from "../src/workflows/decision";
import { env } from "./helpers";

const NOW = Date.parse("2026-09-15T12:00:00.000Z");
const iso = (offsetMs: number) => new Date(NOW + offsetMs).toISOString();

function input(overrides: Partial<DecisionInput> & { progress?: Partial<DecisionInput["progress"]> } = {}): DecisionInput {
  return {
    now: NOW,
    task_id: "task-1",
    generation: 1,
    is_council: false,
    is_recovery: false,
    auto_recovery_enabled: true,
    diagnosed: [],
    recovery_requested: false,
    ...overrides,
    progress: { status: "evaluating", ...(overrides.progress ?? {}) },
  };
}

describe("supervisor decision table", () => {
  it("ends on completed and closed", () => {
    expect(decide(input({ progress: { status: "completed" } }))).toEqual({ kind: "end", outcome: "completed" });
    expect(decide(input({ progress: { status: "closed" } }))).toEqual({ kind: "end", outcome: "closed" });
  });

  it("requests exactly one recovery for a transient failure when the flag is on", () => {
    const decision = decide(input({ progress: { status: "failed", failure_category: "transient" } }));
    expect(decision).toEqual({ kind: "recover", request_id: "sup:task-1:1:recover:v1" });
    expect(decide(input({ progress: { status: "failed", failure_category: "transient" }, recovery_requested: true }))).toEqual({
      kind: "stop",
      reason: "recovery_already_requested",
    });
  });

  it("never recovers when the flag is off, the category is not transient, the task is a council or a recovery", () => {
    expect(decide(input({ progress: { status: "failed", failure_category: "transient" }, auto_recovery_enabled: false }))).toEqual({
      kind: "stop",
      reason: "auto_recovery_disabled",
    });
    expect(decide(input({ progress: { status: "failed", failure_category: "permanent" } }))).toEqual({ kind: "stop", reason: "failure_permanent" });
    expect(decide(input({ progress: { status: "failed" } }))).toEqual({ kind: "stop", reason: "failure_unknown" });
    expect(decide(input({ progress: { status: "failed", failure_category: "transient", phase: "council.attacks" } }))).toEqual({
      kind: "stop",
      reason: "council_failure",
    });
    expect(decide(input({ progress: { status: "failed", failure_category: "transient" }, is_council: true }))).toEqual({ kind: "stop", reason: "council_failure" });
    expect(decide(input({ progress: { status: "failed", failure_category: "transient" }, is_recovery: true }))).toEqual({
      kind: "stop",
      reason: "recovery_task_failed",
    });
  });

  it("diagnoses a queue older than five minutes once per window", () => {
    const queued = input({ progress: { status: "evaluating", queued_at: iso(-6 * 60 * 1000), worker_started_at: null } });
    const expectedId = diagnoseRequestId("task-1", 1, NOW);
    expect(decide(queued)).toEqual({ kind: "diagnose", request_id: expectedId, reason: "queued_too_long" });
    expect(decide({ ...queued, diagnosed: [expectedId] })).toMatchObject({ kind: "wait", reason: "queued" });
    expect(decide(input({ progress: { status: "evaluating", queued_at: iso(-2 * 60 * 1000), worker_started_at: null } }))).toMatchObject({ kind: "wait", reason: "queued" });
  });

  it("waits while a live claim has a future deadline, even with an old heartbeat", () => {
    const live = input({
      progress: { status: "evaluating", worker_started_at: iso(-30 * 60 * 1000), heartbeat_at: iso(-25 * 60 * 1000), phase_deadline_at: iso(3 * 60 * 1000) },
    });
    expect(decide(live)).toEqual({ kind: "wait", reason: "live_claim", timeout_ms: 3 * 60 * 1000 });
  });

  it("diagnoses a stale heartbeat during an active phase without forcing failure", () => {
    const stale = input({
      progress: { status: "evaluating", worker_started_at: iso(-40 * 60 * 1000), heartbeat_at: iso(-21 * 60 * 1000), phase_deadline_at: iso(-60 * 1000) },
    });
    expect(decide(stale)).toEqual({ kind: "diagnose", request_id: diagnoseRequestId("task-1", 1, NOW), reason: "heartbeat_stale" });
    const fresh = input({
      progress: { status: "evaluating", worker_started_at: iso(-40 * 60 * 1000), heartbeat_at: iso(-5 * 60 * 1000), phase_deadline_at: iso(-60 * 1000) },
    });
    expect(decide(fresh)).toMatchObject({ kind: "wait", reason: "active_phase" });
  });

  it("clamps wait timeouts to [60 s, 10 min]", () => {
    expect(waitTimeoutMs(iso(10 * 1000), NOW)).toBe(MIN_WAIT_MS);
    expect(waitTimeoutMs(iso(3 * 60 * 1000), NOW)).toBe(3 * 60 * 1000);
    expect(waitTimeoutMs(iso(45 * 60 * 1000), NOW)).toBe(MAX_WAIT_MS);
    expect(waitTimeoutMs(null, NOW)).toBe(MAX_WAIT_MS);
    expect(waitTimeoutMs("garbage", NOW)).toBe(MAX_WAIT_MS);
  });

  it("produces a byte-identical recovery payload on every call", () => {
    const a = canonicalRecoveryBody("deleg-1", "task-1", 1);
    const b = canonicalRecoveryBody("deleg-1", "task-1", 1);
    expect(a).toBe(b);
    expect(a).toBe(
      '{"delegation":"deleg-1","request_id":"sup:task-1:1:recover:v1","action":"recover_evaluation","blocker":"operational_failure","context":{"summary":"Edge supervisor observed a transient evaluation failure and requests one bounded recovery."}}',
    );
    expect(a).not.toMatch(/\d{4}-\d{2}-\d{2}T/);
  });

  it("uses ten-minute diagnose windows", () => {
    expect(diagnoseRequestId("t", 2, 600_000 * 5 + 1)).toBe("sup:t:2:diag:5");
    expect(diagnoseRequestId("t", 2, 600_000 * 6)).toBe("sup:t:2:diag:6");
  });
});

describe("BuddyTaskSupervisor workflow runtime", () => {
  it("ends with not_eligible when Azure refuses the delegation", async () => {
    const id = "sup-task-ne-1";
    await using instance = await introspectWorkflowInstance(env.BUDDY_TASK_SUPERVISOR, id);
    await instance.modify(async (m) => {
      await m.mockStepResult({ name: "delegate" }, { outcome: "not_eligible" });
    });
    await env.BUDDY_TASK_SUPERVISOR.create({ id, params: { task_id: "task-ne", client_id: "client-a", generation: 1, event_id: "evt-1" } });
    await instance.waitForStatus("complete");
    expect(await instance.getOutput()).toMatchObject({ outcome: "not_eligible", task_id: "task-ne", generation: 1 });
  });

  it("ends on a terminal event after a successful delegation", async () => {
    const id = "sup-task-term-1";
    await using instance = await introspectWorkflowInstance(env.BUDDY_TASK_SUPERVISOR, id);
    await instance.modify(async (m) => {
      await m.mockStepResult({ name: "delegate" }, { outcome: "delegated", delegation: "deleg-1", expires_at: "2026-09-15T13:00:00Z", generation: 1 });
      await m.mockStepResult({ name: "clock-0" }, Date.now());
      await m.mockEvent({ type: "terminal", payload: { data: { status: "completed" } } });
    });
    await env.BUDDY_TASK_SUPERVISOR.create({ id, params: { task_id: "task-term", client_id: "client-a", generation: 1, event_id: "evt-1" } });
    await instance.waitForStatus("complete");
    expect(await instance.getOutput()).toMatchObject({ outcome: "terminal", final_status: "completed" });
  });
});
