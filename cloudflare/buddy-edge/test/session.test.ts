import { SELF } from "cloudflare:test";
import { afterEach, describe, expect, it } from "vitest";
import { CONTENT_SECURITY_POLICY } from "../src/http";
import { SESSION, baseRoutes, cookieHeaderFrom, env, envelope, installAzureStub, messageCollector, progressStubFor, snapshotBody, type AzureStub } from "./helpers";

const ORIGIN = env.ALLOWED_ORIGIN;
let stub: AzureStub | null = null;

afterEach(() => {
  stub?.restore();
  stub = null;
});

async function exchange(): Promise<{ response: Response; cookie: string }> {
  const response = await SELF.fetch("https://edge.test/session/exchange", {
    method: "POST",
    headers: { "Content-Type": "application/json", Origin: ORIGIN },
    body: JSON.stringify({ ticket: "view-ticket-1" }),
  });
  return { response, cookie: cookieHeaderFrom(response) };
}

describe("POST /session/exchange", () => {
  it("sets HttpOnly cookies and never echoes the token", async () => {
    stub = installAzureStub(baseRoutes());
    const { response } = await exchange();
    expect(response.status).toBe(201);
    const text = await response.text();
    expect(text).not.toContain(SESSION.session_token);
    const body = JSON.parse(text) as { task_id: string; expires_at: string };
    expect(body.task_id).toBe("task-1");
    expect(body.expires_at).toBe(SESSION.expires_at);

    const cookies = response.headers.getSetCookie();
    expect(cookies).toHaveLength(2);
    const view = cookies.find((c) => c.startsWith("buddy_view="))!;
    expect(view).toContain(`buddy_view=${SESSION.session_token}`);
    for (const cookie of cookies) {
      expect(cookie).toContain("HttpOnly");
      expect(cookie).toContain("Secure");
      expect(cookie).toContain("SameSite=Strict");
      expect(cookie).toContain("Path=/");
      expect(cookie).toMatch(/Max-Age=\d+/);
      expect(Number(/Max-Age=(\d+)/.exec(cookie)![1])).toBeGreaterThan(0);
    }

    expect(response.headers.get("Content-Security-Policy")).toBe(CONTENT_SECURITY_POLICY);
    expect(response.headers.get("Referrer-Policy")).toBe("no-referrer");
    expect(response.headers.get("X-Content-Type-Options")).toBe("nosniff");
    expect(response.headers.get("Cache-Control")).toBe("no-store");

    const call = stub.callsTo("/api/internal/cloudflare/sessions/exchange")[0]!;
    expect(call.headers.get("X-Buddy-Edge-Key")).toBe("test-edge-service-key");
    expect(JSON.parse(call.body!)).toEqual({ ticket: "view-ticket-1", origin: ORIGIN });
  });

  it("rejects a foreign Origin before calling Azure", async () => {
    stub = installAzureStub(baseRoutes());
    const response = await SELF.fetch("https://edge.test/session/exchange", {
      method: "POST",
      headers: { "Content-Type": "application/json", Origin: "https://evil.example" },
      body: JSON.stringify({ ticket: "view-ticket-1" }),
    });
    expect(response.status).toBe(403);
    expect(await response.json()).toEqual({ error: "origin_not_allowed" });
    expect(stub.calls).toHaveLength(0);
  });

  it("maps a consumed ticket to 410 and a disabled feature to 404", async () => {
    stub = installAzureStub({
      "POST /api/internal/cloudflare/sessions/exchange": () => Response.json({ error: "ticket_invalid" }, { status: 410 }),
    });
    const gone = await exchange();
    expect(gone.response.status).toBe(410);
    expect(gone.response.headers.getSetCookie()).toHaveLength(0);

    stub.restore();
    stub = installAzureStub({
      "POST /api/internal/cloudflare/sessions/exchange": () => new Response(null, { status: 404 }),
    });
    const off = await exchange();
    expect(off.response.status).toBe(404);
  });

  it("requires a ticket in the body", async () => {
    stub = installAzureStub(baseRoutes());
    const response = await SELF.fetch("https://edge.test/session/exchange", { method: "POST", body: "{}" });
    expect(response.status).toBe(400);
  });
});

describe("GET /tasks/:task", () => {
  it("requires the session cookie and rejects other tasks", async () => {
    stub = installAzureStub(baseRoutes());
    expect((await SELF.fetch("https://edge.test/tasks/task-1")).status).toBe(401);
    const { cookie } = await exchange();
    const wrong = await SELF.fetch("https://edge.test/tasks/task-2", { headers: { Cookie: cookie } });
    expect(wrong.status).toBe(403);
  });

  it("returns the snapshot, forwards the session token and reconciles the projection", async () => {
    const events = [envelope({ task_sequence: 1 }), envelope({ task_sequence: 2, data: { status: "evaluating", phase: "evaluation" } })];
    stub = installAzureStub({
      ...baseRoutes(),
      "GET /api/internal/cloudflare/tasks/task-1/snapshot": () => Response.json(snapshotBody({ events, progress: { ...snapshotBody().progress, progress_sequence: 2 } })),
    });
    const { cookie } = await exchange();
    const response = await SELF.fetch("https://edge.test/tasks/task-1?after=0", { headers: { Cookie: cookie } });
    expect(response.status).toBe(200);
    const body = (await response.json()) as { progress: { phase: string }; events: unknown[]; observed_at: string };
    expect(body.progress.phase).toBe("memory");
    expect(body.events).toHaveLength(2);
    expect(Date.parse(body.observed_at)).not.toBeNaN();

    const call = stub.callsTo("/api/internal/cloudflare/tasks/task-1/snapshot")[0]!;
    expect(call.headers.get("X-Buddy-Edge-Session")).toBe(SESSION.session_token);
    expect(call.query.after_sequence).toBe("0");

    const state = await progressStubFor().getState();
    expect(state.last_sequence).toBe(2);
    expect(state.gap).toBe(false);
  });

  it("clears the cookies when Azure reports an expired session", async () => {
    stub = installAzureStub({
      ...baseRoutes(),
      "GET /api/internal/cloudflare/tasks/task-1/snapshot": () => Response.json({ error: "session_expired" }, { status: 401 }),
    });
    const { cookie } = await exchange();
    const response = await SELF.fetch("https://edge.test/tasks/task-1", { headers: { Cookie: cookie } });
    expect(response.status).toBe(401);
    expect(await response.json()).toEqual({ error: "session_expired" });
    expect(response.headers.getSetCookie().every((c) => c.includes("Max-Age=0"))).toBe(true);
  });
});

describe("stream tickets and WebSocket events", () => {
  it("validates the session against Azure with a tail read and mints a ticket", async () => {
    stub = installAzureStub(baseRoutes());
    const { cookie } = await exchange();
    const response = await SELF.fetch("https://edge.test/tasks/task-1/stream-tickets", { method: "POST", headers: { Cookie: cookie, Origin: ORIGIN } });
    expect(response.status).toBe(201);
    const body = (await response.json()) as { ticket: string; expires_at: string };
    expect(body.ticket.length).toBeGreaterThan(30);
    const check = stub.callsTo("/api/internal/cloudflare/tasks/task-1/snapshot")[0]!;
    expect(check.query.after_sequence).toBe("2147483647");

    const foreign = await SELF.fetch("https://edge.test/tasks/task-1/stream-tickets", { method: "POST", headers: { Cookie: cookie, Origin: "https://evil.example" } });
    expect(foreign.status).toBe(403);
  });

  it("accepts a socket once per ticket, replays after a sequence and streams new events", async () => {
    stub = installAzureStub(baseRoutes());
    const { cookie } = await exchange();
    const projection = progressStubFor();
    for (let seq = 1; seq <= 3; seq++) await projection.applyEvent(envelope({ task_sequence: seq }));

    const ticketResponse = await SELF.fetch("https://edge.test/tasks/task-1/stream-tickets", { method: "POST", headers: { Cookie: cookie } });
    const { ticket } = (await ticketResponse.json()) as { ticket: string };

    const upgrade = await SELF.fetch(`https://edge.test/tasks/task-1/events?ticket=${encodeURIComponent(ticket)}`, {
      headers: { Upgrade: "websocket", Cookie: cookie },
    });
    expect(upgrade.status).toBe(101);
    const ws = upgrade.webSocket!;
    ws.accept();
    const inbox = messageCollector(ws);

    const hello = await inbox.next<{ type: string; last_sequence: number }>((m) => (m as { type: string }).type === "hello");
    expect(hello.last_sequence).toBe(3);

    ws.send(JSON.stringify({ type: "resume", after_sequence: 1 }));
    const resumed = await inbox.next<{ type: string; events: { task_sequence: number }[] }>((m) => (m as { type: string }).type === "resume_ok");
    expect(resumed.events.map((e) => e.task_sequence)).toEqual([2, 3]);

    await projection.applyEvent(envelope({ task_sequence: 4, data: { status: "evaluating", phase: "council.frame" } }));
    const live = await inbox.next<{ type: string; envelope: { task_sequence: number } }>((m) => (m as { type: string }).type === "event");
    expect(live.envelope.task_sequence).toBe(4);

    // A gap on the ingest side tells connected dashboards to fetch an authoritative snapshot.
    await projection.applyEvent(envelope({ task_sequence: 9 }));
    const gap = await inbox.next<{ type: string; last_sequence: number }>((m) => (m as { type: string }).type === "snapshot_required");
    expect(gap.last_sequence).toBe(4);

    // The same ticket cannot be used twice.
    const reuse = await SELF.fetch(`https://edge.test/tasks/task-1/events?ticket=${encodeURIComponent(ticket)}`, {
      headers: { Upgrade: "websocket", Cookie: cookie },
    });
    expect(reuse.status).toBe(401);

    ws.close(1000, "done");
  });

  it("answers snapshot_required when the resume point left the buffer", async () => {
    stub = installAzureStub(baseRoutes());
    const { cookie } = await exchange();
    const projection = progressStubFor();
    for (let seq = 1; seq <= 120; seq++) await projection.applyEvent(envelope({ task_sequence: seq }));
    const { ticket } = (await (await SELF.fetch("https://edge.test/tasks/task-1/stream-tickets", { method: "POST", headers: { Cookie: cookie } })).json()) as { ticket: string };
    const upgrade = await SELF.fetch(`https://edge.test/tasks/task-1/events?ticket=${encodeURIComponent(ticket)}`, { headers: { Upgrade: "websocket", Cookie: cookie } });
    const ws = upgrade.webSocket!;
    ws.accept();
    const inbox = messageCollector(ws);
    ws.send(JSON.stringify({ type: "resume", after_sequence: 5 }));
    const reply = await inbox.next<{ type: string; last_sequence: number }>((m) => (m as { type: string }).type === "snapshot_required");
    expect(reply.last_sequence).toBe(120);
    ws.close(1000, "done");
  });

  it("requires the session cookie for the upgrade", async () => {
    const response = await SELF.fetch("https://edge.test/tasks/task-1/events?ticket=x", { headers: { Upgrade: "websocket" } });
    expect(response.status).toBe(401);
  });
});

describe("static assets and internal routes", () => {
  it("serves the dashboard with the security headers", async () => {
    const response = await SELF.fetch("https://edge.test/");
    expect(response.status).toBe(200);
    expect(response.headers.get("Content-Type")).toContain("text/html");
    expect(response.headers.get("Content-Security-Policy")).toBe(CONTENT_SECURITY_POLICY);
    expect(response.headers.get("Referrer-Policy")).toBe("no-referrer");
    const html = await response.text();
    expect(html).not.toMatch(/\son(click|load|error|change|submit|input|key[a-z]*|mouse[a-z]*|focus|blur)\s*=/i);
    expect(html).not.toContain("<script>");
    expect(html).not.toMatch(/<script src="https?:/i);
    expect(html).toContain('src="/app.js"');
  });

  it("guards internal routes with the edge key", async () => {
    expect((await SELF.fetch("https://edge.test/internal/budget")).status).toBe(401);
    const response = await SELF.fetch("https://edge.test/internal/budget", { headers: { "X-Buddy-Edge-Key": "test-edge-service-key" } });
    expect(response.status).toBe(200);
    const body = (await response.json()) as { counters: Record<string, { count: number }> };
    expect(body.counters.events?.count).toBe(0);
  });
});
