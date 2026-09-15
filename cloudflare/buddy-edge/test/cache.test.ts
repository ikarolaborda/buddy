import { SELF } from "cloudflare:test";
import { afterEach, describe, expect, it } from "vitest";
import { azureClient, type ArtifactSummaryResponse } from "../src/azure";
import { getArtifactSummary, summaryCacheKey } from "../src/cache";
import { baseRoutes, budgetStubFor, cookieHeaderFrom, env, installAzureStub, makeEnv, type AzureStub, type StubHandler } from "./helpers";

const METADATA: ArtifactSummaryResponse = {
  artifact_id: "art-1",
  task_id: "task-1",
  type: "document",
  media_type: "text/plain",
  size_bytes: 10,
  content_hash: "sha256-abc",
  processor_version: "p1",
  processing_status: "ready",
  storage_status: "ready",
};
const FULL: ArtifactSummaryResponse = { ...METADATA, summary: { headline: "Full summary from origin" } };
const LOOKUP = { task_id: "task-1", artifact_id: "art-1", client_id: "client-a", session_token: "sess-secret-token-123" };
const KEY = summaryCacheKey("client-a", "art-1", "sha256-abc", "p1");
const PATH = "/api/internal/cloudflare/tasks/task-1/artifacts/art-1/summary";

let stub: AzureStub | null = null;
afterEach(() => {
  stub?.restore();
  stub = null;
});

function summaryRoute(): StubHandler {
  return (_request, url) => Response.json(url.searchParams.get("metadata_only") === "1" ? METADATA : FULL);
}

describe("artifact summary cache", () => {
  it("bypasses KV entirely when READ_CACHE_ENABLED is off", async () => {
    stub = installAzureStub({ [`GET ${PATH}`]: summaryRoute() });
    await env.BUDDY_READ_CACHE.put(KEY, JSON.stringify({ ...FULL, summary: "stale" }));
    const outcome = await getArtifactSummary(makeEnv({ READ_CACHE_ENABLED: "false" }), azureClient(env), LOOKUP);
    expect(outcome).toMatchObject({ ok: true, source: "origin", summary: FULL });
    expect(stub.calls).toHaveLength(1);
    expect(stub.calls[0]!.query.metadata_only).toBeUndefined();
    expect(stub.calls[0]!.headers.get("X-Buddy-Edge-Session")).toBe(LOOKUP.session_token);
  });

  it("authorizes with a metadata read, then fills KV on a miss", async () => {
    stub = installAzureStub({ [`GET ${PATH}`]: summaryRoute() });
    const outcome = await getArtifactSummary(makeEnv({ READ_CACHE_ENABLED: "true" }), azureClient(env), LOOKUP);
    expect(outcome).toMatchObject({ ok: true, source: "origin", summary: FULL });
    expect(stub.calls.map((c) => c.query.metadata_only ?? "full")).toEqual(["1", "full"]);
    expect(await env.BUDDY_READ_CACHE.get(KEY, "json")).toEqual(FULL);
    const report = await budgetStubFor().read();
    expect(report.counters.cache_misses.count).toBe(1);
    expect(report.counters.cache_hits.count).toBe(0);
  });

  it("serves a hit after authorization without a full origin read", async () => {
    stub = installAzureStub({ [`GET ${PATH}`]: summaryRoute() });
    await env.BUDDY_READ_CACHE.put(KEY, JSON.stringify(FULL));
    const outcome = await getArtifactSummary(makeEnv({ READ_CACHE_ENABLED: "true" }), azureClient(env), LOOKUP);
    expect(outcome).toMatchObject({ ok: true, source: "cache", summary: FULL });
    expect(stub.calls).toHaveLength(1);
    expect(stub.calls[0]!.query.metadata_only).toBe("1");
    expect((await budgetStubFor().read()).counters.cache_hits.count).toBe(1);
  });

  it("fails closed when origin authorization fails even if KV has a value", async () => {
    stub = installAzureStub({ [`GET ${PATH}`]: () => Response.json({ error: "session_expired" }, { status: 401 }) });
    await env.BUDDY_READ_CACHE.put(KEY, JSON.stringify(FULL));
    const outcome = await getArtifactSummary(makeEnv({ READ_CACHE_ENABLED: "true" }), azureClient(env), LOOKUP);
    expect(outcome).toEqual({ ok: false, status: 401, error: "session_expired" });
    expect(stub.calls).toHaveLength(1);
  });

  it("changes the key when the content hash or processor version changes", async () => {
    expect(summaryCacheKey("c", "a", "h1", "p1")).not.toBe(summaryCacheKey("c", "a", "h2", "p1"));
    expect(summaryCacheKey("c", "a", "h1", "p1")).not.toBe(summaryCacheKey("c", "a", "h1", "p2"));
    expect(summaryCacheKey("c1", "a", "h1", "p1")).not.toBe(summaryCacheKey("c2", "a", "h1", "p1"));
  });

  it("is reachable through the session-guarded route", async () => {
    stub = installAzureStub({ ...baseRoutes(), [`GET ${PATH}`]: summaryRoute() });
    const exchange = await SELF.fetch("https://edge.test/session/exchange", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ticket: "t" }),
    });
    const cookie = cookieHeaderFrom(exchange);
    expect((await SELF.fetch("https://edge.test/tasks/task-1/artifacts/art-1/summary")).status).toBe(401);
    const response = await SELF.fetch("https://edge.test/tasks/task-1/artifacts/art-1/summary", { headers: { Cookie: cookie } });
    expect(response.status).toBe(200);
    expect(response.headers.get("X-Buddy-Cache")).toBe("origin");
    expect(response.headers.get("Cache-Control")).toBe("no-store");
    expect(await response.json()).toEqual(FULL);
  });
});
