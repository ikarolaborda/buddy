import { createExecutionContext, createMessageBatch, getQueueResult, runInDurableObject, SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import { utcDay, type BuddyEdgeBudget } from "../src/do/edge-budget";
import { CONTENT_SECURITY_POLICY } from "../src/http";
import worker from "../src/index";
import { handleRequest } from "../src/router";
import { budgetStubFor, env, envelope, internalHeaders, makeEnv } from "./helpers";

interface RecordedSend {
  body: unknown;
  options: unknown;
}

/** `cloudflare:test` 1.1.9 has no producer-side inspection, so the two queue bindings are replaced by recorders. */
function recordingQueue(behaviour: { fail?: Error } = {}) {
  const sent: RecordedSend[] = [];
  const binding = {
    async send(body: unknown, options?: unknown) {
      if (behaviour.fail) throw behaviour.fail;
      sent.push({ body, options });
    },
    async sendBatch() {
      throw new Error("sendBatch is not used by the ingest route");
    },
  } as unknown as Queue;
  return { sent, binding };
}

function ingestRequest(body: unknown, headers: Record<string, string> = internalHeaders()): Request {
  return new Request("https://edge.test/internal/events", {
    method: "POST",
    headers: { "Content-Type": "application/json", ...headers },
    body: typeof body === "string" ? body : JSON.stringify(body),
  });
}

async function ingest(body: unknown, overrides: Record<string, unknown> = {}) {
  const events = recordingQueue();
  const artifacts = recordingQueue();
  const response = await handleRequest(
    ingestRequest(body),
    makeEnv({ BUDDY_EVENTS: events.binding, BUDDY_ARTIFACT_EVENTS: artifacts.binding, ...overrides }),
    createExecutionContext(),
  );
  return { response, events, artifacts };
}

async function exhaustEventsBudget(): Promise<void> {
  await runInDurableObject(budgetStubFor(), (_instance: BuddyEdgeBudget, state) => {
    state.storage.sql.exec("INSERT INTO counters (day, kind, count, warned) VALUES (?, 'events', ?, 1)", utcDay(Date.now()), 50_000);
  });
}

describe("POST /internal/events", () => {
  it("requires the edge key", async () => {
    const missing = await SELF.fetch(ingestRequest(envelope(), {}));
    expect(missing.status).toBe(401);
    expect(await missing.json()).toEqual({ error: "edge_key_invalid" });
    const wrong = await SELF.fetch(ingestRequest(envelope(), { "X-Buddy-Edge-Key": "nope" }));
    expect(wrong.status).toBe(401);
    expect((await budgetStubFor().read()).counters.events.count).toBe(0);
  });

  it("answers 422 for bodies that are not a valid envelope", async () => {
    const cases: [unknown, string][] = [
      ["not json", "body_not_json_object"],
      [{ body: envelope() }, "unsupported_schema_version"],
      [{ ...envelope(), schema_version: 7 }, "unsupported_schema_version"],
      [{ ...envelope(), task_id: "" }, "missing_task_id"],
      [{ ...envelope(), type: "other.event" }, "unknown_type"],
      [[envelope()], "envelope_not_object"],
    ];
    for (const [body, reason] of cases) {
      const response = await SELF.fetch(ingestRequest(body));
      expect(response.status).toBe(422);
      expect(await response.json()).toEqual({ error: "invalid_envelope", reason });
    }
    expect((await budgetStubFor().read()).counters.events.count).toBe(0);
  });

  it("queues a valid envelope unchanged, counts it and answers 202", async () => {
    const event = envelope({ task_sequence: 3 });
    const { response, events, artifacts } = await ingest(event);
    expect(response.status).toBe(202);
    expect(await response.json()).toEqual({ queued: true, event_id: event.event_id });
    expect(response.headers.get("Content-Security-Policy")).toBe(CONTENT_SECURITY_POLICY);
    expect(response.headers.get("Cache-Control")).toBe("no-store");

    expect(events.sent).toHaveLength(1);
    expect(events.sent[0]!.body).toEqual(event);
    expect(events.sent[0]!.options).toEqual({ contentType: "json" });
    expect(artifacts.sent).toHaveLength(0);
    expect((await budgetStubFor().read()).counters.events.count).toBe(1);

    // The queued message is exactly what the consumer expects: it is applied and acknowledged.
    const batch = createMessageBatch("buddy-events-preview", [{ id: "ingested-1", timestamp: new Date(), attempts: 1, body: events.sent[0]!.body }]);
    const ctx = createExecutionContext();
    await worker.queue(batch, env, ctx);
    expect((await getQueueResult(batch, ctx)).explicitAcks).toEqual(["ingested-1"]);
  });

  it("routes artifact events to the artifacts queue", async () => {
    const event = envelope({ type: "buddy.task.artifact.available.v1", data: { artifact_id: "art-1" } });
    const { response, events, artifacts } = await ingest(event);
    expect(response.status).toBe(202);
    expect(artifacts.sent.map((s) => s.body)).toEqual([event]);
    expect(events.sent).toHaveLength(0);
  });

  it("answers 429 once the daily events budget is exhausted", async () => {
    await exhaustEventsBudget();
    const { response, events, artifacts } = await ingest(envelope());
    expect(response.status).toBe(429);
    expect(await response.json()).toEqual({ error: "budget_exhausted" });
    expect(events.sent).toHaveLength(0);
    expect(artifacts.sent).toHaveLength(0);
    expect((await budgetStubFor().read()).counters.events.count).toBe(50_000);
  });

  it("still queues terminal events when the budget is exhausted", async () => {
    await exhaustEventsBudget();
    const terminal = envelope({ type: "buddy.task.terminal.v1", data: { status: "completed" } });
    const { response, events } = await ingest(terminal);
    expect(response.status).toBe(202);
    expect(events.sent.map((s) => s.body)).toEqual([terminal]);
  });

  it("answers 503 when the queue refuses the message", async () => {
    const failing = recordingQueue({ fail: new Error("queue unavailable") });
    const response = await handleRequest(ingestRequest(envelope()), makeEnv({ BUDDY_EVENTS: failing.binding }), createExecutionContext());
    expect(response.status).toBe(503);
    expect(await response.json()).toEqual({ error: "queue_unavailable" });
  });

  it("accepts POST only", async () => {
    const response = await SELF.fetch("https://edge.test/internal/events", { headers: internalHeaders() });
    expect(response.status).toBe(405);
  });
});
