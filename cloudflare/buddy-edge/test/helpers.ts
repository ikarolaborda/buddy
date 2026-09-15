import { env as workerEnv } from "cloudflare:workers";
import { vi } from "vitest";
import type { EdgeEnv } from "../src/env";
import type { EventEnvelope } from "../src/envelope";
import type { BuddyEdgeBudget } from "../src/do/edge-budget";
import type { BuddyTaskProgress } from "../src/do/task-progress";

export const env = workerEnv as unknown as EdgeEnv;
export const EDGE_KEY = "test-edge-service-key";

export function makeEnv(overrides: Partial<EdgeEnv> & Record<string, unknown> = {}): EdgeEnv {
  return { ...env, ...overrides } as EdgeEnv;
}

export type StubHandler = (request: Request, url: URL, body: string | null) => Response | Promise<Response>;

export interface RecordedCall {
  method: string;
  path: string;
  query: Record<string, string>;
  headers: Headers;
  body: string | null;
}

export interface AzureStub {
  calls: RecordedCall[];
  callsTo(pathPrefix: string): RecordedCall[];
  restore(): void;
}

/** Replaces global fetch with a router keyed by `METHOD /path`. Unmatched Azure calls return 599 and are recorded. */
export function installAzureStub(routes: Record<string, StubHandler>): AzureStub {
  const calls: RecordedCall[] = [];
  const stubbed = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const request = new Request(input, init);
    const url = new URL(request.url);
    const body = request.method === "GET" || request.method === "HEAD" ? null : await request.text();
    const query: Record<string, string> = {};
    url.searchParams.forEach((value, key) => {
      query[key] = value;
    });
    calls.push({ method: request.method, path: url.pathname, query, headers: new Headers(request.headers), body });
    const handler = routes[`${request.method} ${url.pathname}`];
    if (!handler) return Response.json({ error: "unmatched_stub", path: url.pathname }, { status: 599 });
    return handler(request, url, body);
  };
  vi.stubGlobal("fetch", stubbed);
  return {
    calls,
    callsTo(pathPrefix: string) {
      return calls.filter((call) => call.path.startsWith(pathPrefix));
    },
    restore() {
      vi.unstubAllGlobals();
    },
  };
}

let counter = 0;
let testSerial = 0;

/** Called from the setup file before every test: a fresh client id gives every test its own projection object. */
export function nextTestIdentity(): void {
  testSerial += 1;
}

export function currentClientId(): string {
  return `client-${testSerial}`;
}

export function envelope(overrides: Partial<EventEnvelope> = {}): EventEnvelope {
  counter += 1;
  return {
    schema_version: 1,
    event_id: `01EVT${String(counter).padStart(8, "0")}`,
    type: "buddy.task.progress.v1",
    client_id: currentClientId(),
    task_id: "task-1",
    task_sequence: 1,
    state_version: 1,
    generation: 1,
    occurred_at: "2026-09-15T12:00:00.000Z",
    trace_id: "trace-1",
    data: { status: "evaluating", phase: "memory" },
    ...overrides,
  };
}

export function progressStubFor(clientId = currentClientId(), taskId = "task-1"): DurableObjectStub<BuddyTaskProgress> {
  const namespace = env.BUDDY_TASK_PROGRESS as DurableObjectNamespace<BuddyTaskProgress>;
  return namespace.get(namespace.idFromName(`${clientId}:${taskId}`));
}

export function budgetStubFor(): DurableObjectStub<BuddyEdgeBudget> {
  const namespace = env.BUDDY_EDGE_BUDGET as DurableObjectNamespace<BuddyEdgeBudget>;
  return namespace.get(namespace.idFromName("budget"));
}

const SESSION_EXPIRES_AT = new Date(Date.now() + 15 * 60 * 1000).toISOString();
const SESSION_HARD_EXPIRES_AT = new Date(Date.now() + 60 * 60 * 1000).toISOString();

/** Azure exchange response used by the stub; `client_id` follows the current test identity. */
export const SESSION = {
  session_token: "sess-secret-token-123",
  task_id: "task-1",
  get client_id(): string {
    return currentClientId();
  },
  scope: ["tasks:read"],
  expires_at: SESSION_EXPIRES_AT,
  hard_expires_at: SESSION_HARD_EXPIRES_AT,
};

export function snapshotBody(overrides: Record<string, unknown> = {}) {
  return {
    task_id: "task-1",
    progress: {
      status: "evaluating",
      phase: "memory",
      phase_started_at: "2026-09-15T12:00:00.000Z",
      phase_deadline_at: "2026-09-15T12:10:00.000Z",
      queued_at: "2026-09-15T11:59:00.000Z",
      worker_started_at: "2026-09-15T12:00:00.000Z",
      heartbeat_at: "2026-09-15T12:00:30.000Z",
      progress_sequence: 0,
      progress_observed_at: "2026-09-15T12:00:31.000Z",
      next_poll_after_ms: 15000,
      generation: 1,
      state_version: 1,
      recovery_task_id: null,
      failure_category: null,
    },
    events: [],
    events_truncated: false,
    session: { expires_at: SESSION.expires_at },
    ...overrides,
  };
}

/** Builds a Cookie header from the Set-Cookie headers of a response. */
export function cookieHeaderFrom(response: Response): string {
  return response.headers
    .getSetCookie()
    .map((cookie) => cookie.split(";")[0]!)
    .join("; ");
}

export function baseRoutes(): Record<string, StubHandler> {
  return {
    "POST /api/internal/cloudflare/sessions/exchange": () => Response.json({ ...SESSION, client_id: SESSION.client_id }, { status: 201 }),
    "GET /api/internal/cloudflare/tasks/task-1/snapshot": () => Response.json(snapshotBody()),
  };
}

/** Collects WebSocket messages and lets a test wait for the next one matching a predicate. */
export function messageCollector(ws: WebSocket) {
  const received: unknown[] = [];
  const waiters: { predicate: (m: unknown) => boolean; resolve: (m: unknown) => void }[] = [];
  ws.addEventListener("message", (event) => {
    let parsed: unknown;
    try {
      parsed = JSON.parse(String((event as MessageEvent).data));
    } catch {
      parsed = (event as MessageEvent).data;
    }
    received.push(parsed);
    for (const waiter of [...waiters]) {
      if (waiter.predicate(parsed)) {
        waiters.splice(waiters.indexOf(waiter), 1);
        waiter.resolve(parsed);
      }
    }
  });
  return {
    received,
    next<T = { type: string }>(predicate: (m: unknown) => boolean = () => true, timeoutMs = 5000): Promise<T> {
      const existing = received.find(predicate);
      if (existing) {
        received.splice(received.indexOf(existing), 1);
        return Promise.resolve(existing as T);
      }
      return new Promise<T>((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error("timeout waiting for websocket message")), timeoutMs);
        waiters.push({
          predicate,
          resolve: (m) => {
            clearTimeout(timer);
            received.splice(received.indexOf(m), 1);
            resolve(m as T);
          },
        });
      });
    },
  };
}
