import { runInDurableObject } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import { REPLAY_MAX_EVENTS, type BuddyTaskProgress } from "../src/do/task-progress";
import { envelope, progressStubFor } from "./helpers";

describe("BuddyTaskProgress ordering", () => {
  it("applies in order, ignores duplicates and old sequences, parks gaps", async () => {
    const stub = progressStubFor();
    const first = await stub.applyEvent(envelope({ task_sequence: 1 }));
    expect(first.outcome).toBe("applied");
    expect(first.last_sequence).toBe(1);

    const duplicate = await stub.applyEvent(envelope({ task_sequence: 1 }));
    expect(duplicate.outcome).toBe("duplicate");

    const gap = await stub.applyEvent(envelope({ task_sequence: 3 }));
    expect(gap.outcome).toBe("gap");
    expect(gap.gap).toBe(true);
    let state = await stub.getState();
    expect(state.pending_sequences).toEqual([3]);
    expect(state.last_sequence).toBe(1);

    const fill = await stub.applyEvent(envelope({ task_sequence: 2 }));
    expect(fill.outcome).toBe("applied");
    expect(fill.last_sequence).toBe(3);
    expect(fill.gap).toBe(false);
    state = await stub.getState();
    expect(state.pending_sequences).toEqual([]);
    expect(state.buffer_size).toBe(3);
  });

  it("rejects malformed envelopes without changing state", async () => {
    const stub = progressStubFor();
    expect(await stub.applyEvent({ schema_version: 2 })).toMatchObject({ outcome: "rejected", reason: "unsupported_schema_version", last_sequence: 0 });
    expect(await stub.applyEvent({ ...envelope(), task_sequence: 0 })).toMatchObject({ outcome: "rejected", reason: "invalid_task_sequence" });
    expect((await stub.getState()).last_sequence).toBe(0);
  });

  it("reconcile fills gaps from an authorized snapshot and clears the flag", async () => {
    const stub = progressStubFor();
    await stub.applyEvent(envelope({ task_sequence: 1 }));
    await stub.applyEvent(envelope({ task_sequence: 4 }));
    expect((await stub.getState()).gap).toBe(true);

    const result = await stub.reconcile({ events: [envelope({ task_sequence: 3 }), envelope({ task_sequence: 2 })] });
    expect(result.last_sequence).toBe(4);
    expect(result.gap).toBe(false);
    expect(result.applied).toBe(3);
    const state = await stub.getState();
    expect(state.pending_sequences).toEqual([]);
    expect(state.buffer_size).toBe(4);
  });

  it("reconcile fast-forwards to the authoritative progress sequence when history is truncated", async () => {
    const stub = progressStubFor();
    await stub.applyEvent(envelope({ task_sequence: 1 }));
    await stub.applyEvent(envelope({ task_sequence: 10 }));
    const result = await stub.reconcile({ progress: { status: "evaluating", progress_sequence: 9 }, events: [] });
    expect(result.last_sequence).toBe(10);
    expect(result.gap).toBe(false);
    const state = await stub.getState();
    expect(state.progress?.status).toBe("evaluating");
  });

  it("keeps the replay buffer to the newest 100 events", async () => {
    const stub = progressStubFor();
    for (let seq = 1; seq <= REPLAY_MAX_EVENTS + 30; seq++) {
      await stub.applyEvent(envelope({ task_sequence: seq }));
    }
    const state = await stub.getState();
    expect(state.last_sequence).toBe(130);
    expect(state.buffer_size).toBe(REPLAY_MAX_EVENTS);
    expect(state.buffer_min_sequence).toBe(31);

    const covered = await stub.eventsAfter(30);
    expect(covered).not.toBeNull();
    expect(covered!.length).toBe(100);
    expect(covered![0]!.task_sequence).toBe(31);

    const uncovered = await stub.eventsAfter(29);
    expect(uncovered).toBeNull();

    const nothingNew = await stub.eventsAfter(130);
    expect(nothingNew).toEqual([]);
  });

  it("drops buffered events older than 24 hours", async () => {
    const stub = progressStubFor();
    for (let seq = 1; seq <= 5; seq++) await stub.applyEvent(envelope({ task_sequence: seq }));
    await runInDurableObject(stub, (_instance: BuddyTaskProgress, state) => {
      state.storage.sql.exec("UPDATE events SET received_at = ? WHERE sequence <= 3", Date.now() - 25 * 60 * 60 * 1000);
    });
    await stub.applyEvent(envelope({ task_sequence: 6 }));
    const state = await stub.getState();
    expect(state.buffer_size).toBe(3);
    expect(state.buffer_min_sequence).toBe(4);
    expect(await stub.eventsAfter(2)).toBeNull();
  });

  it("projects a snapshot from events and schedules the terminal close", async () => {
    const stub = progressStubFor();
    await stub.applyEvent(envelope({ task_sequence: 1, data: { status: "evaluating", phase: "council.frame", worker_started_at: "2026-09-15T12:00:00Z" } }));
    let state = await stub.getState();
    expect(state.progress?.phase).toBe("council.frame");
    expect(state.close_at).toBeNull();

    const terminal = await stub.applyEvent(
      envelope({ task_sequence: 2, type: "buddy.task.terminal.v1", data: { status: "failed", failure_category: "transient" } }),
    );
    expect(terminal.terminal).toBe(true);
    state = await stub.getState();
    expect(state.progress?.status).toBe("failed");
    expect(state.progress?.failure_category).toBe("transient");
    expect(state.close_at).not.toBeNull();
  });
});

describe("BuddyTaskProgress supervision bookkeeping", () => {
  it("asks for a supervisor once per generation and retries while pending", async () => {
    const stub = progressStubFor();
    const first = await stub.applyEvent(envelope({ task_sequence: 1, generation: 3, event_id: "evt-first" }));
    expect(first.supervision).toEqual({ generation: 3, event_id: "evt-first" });

    // Not yet created: the next event asks again with the original event id.
    await stub.markSupervisorFailed(3);
    const second = await stub.applyEvent(envelope({ task_sequence: 2, generation: 3 }));
    expect(second.supervision).toEqual({ generation: 3, event_id: "evt-first" });

    await stub.markSupervisorCreated(3);
    const third = await stub.applyEvent(envelope({ task_sequence: 3, generation: 3 }));
    expect(third.supervision).toBeNull();

    const nonProgress = await stub.applyEvent(envelope({ task_sequence: 4, generation: 4, type: "buddy.task.artifact.available.v1", data: {} }));
    expect(nonProgress.supervision).toBeNull();

    const state = await stub.getState();
    expect(state.supervision).toEqual([{ generation: 3, status: "created", attempts: 1 }]);
  });
});

describe("BuddyTaskProgress tickets", () => {
  it("consumes a ticket exactly once", async () => {
    const stub = progressStubFor();
    const minted = await stub.mintTicket("client-a", "task-1");
    expect(minted.ticket.length).toBeGreaterThan(30);
    expect(Date.parse(minted.expires_at) - Date.now()).toBeLessThanOrEqual(60_000);

    expect(await stub.consumeTicket(minted.ticket)).toEqual({ client_id: "client-a", task_id: "task-1" });
    expect(await stub.consumeTicket(minted.ticket)).toBeNull();
    expect(await stub.consumeTicket("not-a-ticket")).toBeNull();
  });

  it("rejects expired tickets", async () => {
    const stub = progressStubFor();
    const minted = await stub.mintTicket("client-a", "task-1");
    await runInDurableObject(stub, (_instance: BuddyTaskProgress, state) => {
      state.storage.sql.exec("UPDATE tickets SET expires_at = ?", Date.now() - 1);
    });
    expect(await stub.consumeTicket(minted.ticket)).toBeNull();
  });

  it("never accepts a socket without a valid ticket", async () => {
    const stub = progressStubFor();
    const noTicket = await stub.fetch(new Request("https://do/events", { headers: { Upgrade: "websocket" } }));
    expect(noTicket.status).toBe(400);
    const badTicket = await stub.fetch(new Request("https://do/events?ticket=bogus", { headers: { Upgrade: "websocket" } }));
    expect(badTicket.status).toBe(401);
    const notUpgrade = await stub.fetch(new Request("https://do/events?ticket=bogus"));
    expect(notUpgrade.status).toBe(426);
  });
});
