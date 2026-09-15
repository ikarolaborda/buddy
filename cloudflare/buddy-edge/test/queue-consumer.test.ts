import { createExecutionContext, createMessageBatch, getQueueResult, introspectWorkflowInstance } from "cloudflare:test";
import { describe, expect, it, vi } from "vitest";
import { consumeBatch, isAlreadyExistsError, retryDelaySeconds, supervisorInstanceId } from "../src/consumers/events";
import type { BuddyTaskProgress } from "../src/do/task-progress";
import worker from "../src/index";
import { budgetStubFor, currentClientId, env, envelope, makeEnv, progressStubFor } from "./helpers";

function batchOf(bodies: unknown[], attempts = 1) {
  return createMessageBatch(
    "buddy-events-preview",
    bodies.map((body, index) => ({ id: `msg-${index + 1}`, timestamp: new Date("2026-09-15T12:00:00Z"), attempts, body })),
  );
}

describe("queue consumer acknowledgement", () => {
  it("acks only after the projection accepted the event", async () => {
    const batch = batchOf([envelope({ task_sequence: 1 }), envelope({ task_sequence: 2 })]);
    const ctx = createExecutionContext();
    await worker.queue(batch, env, ctx);
    const result = await getQueueResult(batch, ctx);
    expect(result.explicitAcks).toEqual(["msg-1", "msg-2"]);
    expect(result.retryMessages).toEqual([]);
    expect((await progressStubFor().getState()).last_sequence).toBe(2);
    expect((await budgetStubFor().read()).counters.events.count).toBe(2);
  });

  it("retries with backoff when the projection fails, without acking", async () => {
    const failing = {
      idFromName: () => ({}),
      get: () => ({ applyEvent: async () => { throw new Error("do_unavailable"); } }),
    };
    const batch = batchOf([envelope({ task_sequence: 1 })], 2);
    const ctx = createExecutionContext();
    await consumeBatch(batch, makeEnv({ BUDDY_TASK_PROGRESS: failing as unknown as DurableObjectNamespace<BuddyTaskProgress> }), "events");
    const result = await getQueueResult(batch, ctx);
    expect(result.explicitAcks).toEqual([]);
    // The local harness records retried ids only; the delay schedule is asserted directly.
    expect(result.retryMessages.map((m) => m.msgId)).toEqual(["msg-1"]);
    expect(retryDelaySeconds(1)).toBe(30);
    expect(retryDelaySeconds(2)).toBe(60);
    expect(retryDelaySeconds(100)).toBe(600);
  });

  it("sends unknown schema versions and malformed bodies down the DLQ path", async () => {
    const batch = batchOf([{ ...envelope(), schema_version: 7 }, "not-json-object", null], 3);
    const ctx = createExecutionContext();
    await worker.queue(batch, env, ctx);
    const result = await getQueueResult(batch, ctx);
    expect(result.explicitAcks).toEqual([]);
    expect(result.retryMessages.map((m) => m.msgId)).toEqual(["msg-1", "msg-2", "msg-3"]);
    expect((await progressStubFor().getState()).last_sequence).toBe(0);
  });

  it("routes the artifacts queue into the same projection", async () => {
    const batch = createMessageBatch("buddy-artifacts-preview", [
      { id: "a-1", timestamp: new Date(), attempts: 1, body: envelope({ task_sequence: 1, type: "buddy.task.artifact.available.v1", data: { artifact_id: "art-1" } }) },
    ]);
    const ctx = createExecutionContext();
    await worker.queue(batch, env, ctx);
    const result = await getQueueResult(batch, ctx);
    expect(result.explicitAcks).toEqual(["a-1"]);
    expect((await progressStubFor().getState()).last_sequence).toBe(1);
  });
});

describe("supervisor creation from the consumer", () => {
  function fakeWorkflow(behaviour: { createError?: Error | ((attempt: number) => Error | undefined) } = {}) {
    let attempt = 0;
    const create = vi.fn(async () => {
      attempt += 1;
      const error = typeof behaviour.createError === "function" ? behaviour.createError(attempt) : behaviour.createError;
      if (error) throw error;
      return { id: "x" };
    });
    const sendEvent = vi.fn(async () => undefined);
    const get = vi.fn(async () => ({ sendEvent }));
    return { binding: { create, get } as unknown as Workflow, create, get, sendEvent };
  }

  it("does nothing while SUPERVISION_ENABLED is false", async () => {
    const wf = fakeWorkflow();
    const batch = batchOf([envelope({ task_sequence: 1 })]);
    await consumeBatch(batch, makeEnv({ BUDDY_TASK_SUPERVISOR: wf.binding }), "events");
    expect(wf.create).not.toHaveBeenCalled();
  });

  it("creates one supervisor per task generation and passes the first event", async () => {
    const wf = fakeWorkflow();
    const supervised = makeEnv({ SUPERVISION_ENABLED: "true", BUDDY_TASK_SUPERVISOR: wf.binding });
    const first = batchOf([envelope({ task_sequence: 1, generation: 2, event_id: "evt-a" })]);
    const ctx = createExecutionContext();
    await consumeBatch(first, supervised, "events");
    expect((await getQueueResult(first, ctx)).explicitAcks).toEqual(["msg-1"]);
    expect(wf.create).toHaveBeenCalledTimes(1);
    expect(wf.create).toHaveBeenCalledWith({
      id: supervisorInstanceId("task-1", 2),
      params: { task_id: "task-1", client_id: currentClientId(), generation: 2, event_id: "evt-a", is_council: false, is_recovery: false },
    });

    await consumeBatch(batchOf([envelope({ task_sequence: 2, generation: 2 })]), supervised, "events");
    expect(wf.create).toHaveBeenCalledTimes(1);
    expect((await progressStubFor().getState()).supervision).toEqual([{ generation: 2, status: "created", attempts: 0 }]);
    expect((await budgetStubFor().read()).counters.workflows.count).toBe(1);
  });

  it("treats an existing instance as success", async () => {
    const wf = fakeWorkflow({ createError: new Error("Workflow instance with ID sup-task-1-1 already exists") });
    const supervised = makeEnv({ SUPERVISION_ENABLED: "true", BUDDY_TASK_SUPERVISOR: wf.binding });
    const batch = batchOf([envelope({ task_sequence: 1 })]);
    const ctx = createExecutionContext();
    await consumeBatch(batch, supervised, "events");
    expect((await getQueueResult(batch, ctx)).explicitAcks).toEqual(["msg-1"]);
    expect((await progressStubFor().getState()).supervision).toEqual([{ generation: 1, status: "created", attempts: 0 }]);
    expect(await budgetStubFor().listReconciliation()).toEqual([]);
  });

  it("records other creation failures for reconciliation and retries on the next event", async () => {
    const wf = fakeWorkflow({ createError: (attempt) => (attempt === 1 ? new Error("workflows unavailable") : undefined) });
    const supervised = makeEnv({ SUPERVISION_ENABLED: "true", BUDDY_TASK_SUPERVISOR: wf.binding });
    const batch = batchOf([envelope({ task_sequence: 1 })]);
    const ctx = createExecutionContext();
    await consumeBatch(batch, supervised, "events");
    expect((await getQueueResult(batch, ctx)).explicitAcks).toEqual(["msg-1"]);
    const items = await budgetStubFor().listReconciliation();
    expect(items).toHaveLength(1);
    expect(items[0]!.kind).toBe("workflow_create_failed");
    expect((await progressStubFor().getState()).supervision).toEqual([{ generation: 1, status: "pending", attempts: 1 }]);

    await consumeBatch(batchOf([envelope({ task_sequence: 2 })]), supervised, "events");
    expect(wf.create).toHaveBeenCalledTimes(2);
    expect((await progressStubFor().getState()).supervision).toEqual([{ generation: 1, status: "created", attempts: 1 }]);
  });

  it("forwards terminal envelopes to the supervisor instance", async () => {
    const wf = fakeWorkflow();
    const supervised = makeEnv({ SUPERVISION_ENABLED: "true", BUDDY_TASK_SUPERVISOR: wf.binding });
    await consumeBatch(batchOf([envelope({ task_sequence: 1 })]), supervised, "events");
    const terminal = envelope({ task_sequence: 2, type: "buddy.task.terminal.v1", data: { status: "completed" } });
    await consumeBatch(batchOf([terminal]), supervised, "events");
    expect(wf.get).toHaveBeenCalledWith(supervisorInstanceId("task-1", 1));
    expect(wf.sendEvent).toHaveBeenCalledWith({ type: "terminal", payload: terminal });
  });

  it("treats duplicate instance ids as success", async () => {
    expect(isAlreadyExistsError(new Error("Workflow instance with ID sup-task-1-1 already exists"))).toBe(true);
    expect(isAlreadyExistsError(new Error("instance.already.exists"))).toBe(true);
    expect(isAlreadyExistsError(new Error("HTTP 409 Conflict"))).toBe(true);
    expect(isAlreadyExistsError(new Error("workflows unavailable"))).toBe(false);

    // The local Workflows runtime accepts a repeated id without throwing; production rejects it and the
    // consumer treats that rejection as success. Either way the second create must not fail the consumer.
    const id = "sup-task-idempotent-1";
    await using instance = await introspectWorkflowInstance(env.BUDDY_TASK_SUPERVISOR, id);
    await instance.modify(async (m) => {
      await m.mockStepResult({ name: "delegate" }, { outcome: "not_eligible" });
    });
    const params = { task_id: "task-idempotent", client_id: "client-a", generation: 1, event_id: "evt" };
    await env.BUDDY_TASK_SUPERVISOR.create({ id, params });
    let duplicateError: unknown = null;
    try {
      await env.BUDDY_TASK_SUPERVISOR.create({ id, params });
    } catch (error) {
      duplicateError = error;
    }
    if (duplicateError !== null) expect(isAlreadyExistsError(duplicateError)).toBe(true);
    await instance.waitForStatus("complete");
  });
});
