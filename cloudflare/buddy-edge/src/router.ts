import { azureClient } from "./azure";
import { getArtifactSummary } from "./cache";
import { handleCaptureRequest } from "./captures";
import type { CaptureRunner } from "./captures/runner";
import { EDGE_KEY_HEADER, SCOPE_COOKIE, SESSION_COOKIE, type EdgeEnv } from "./env";
import {
  applySecurityHeaders,
  clearSessionCookies,
  decodeScope,
  errorJson,
  json,
  parseCookies,
  readJsonBody,
  secretsEqual,
  sessionCookies,
} from "./http";
import { handleEventIngest } from "./ingest";
import { handleDownload, handleObjectContent, handleObjectCopy, handleObjectDelete, handleObjectMeta, handleUpload } from "./objects";
import { budgetStub, progressStub } from "./stubs";

export interface RouterDeps {
  captureRunner?: CaptureRunner;
}

interface BrowserSession {
  token: string;
  client_id: string;
  task_id: string;
}

const SNAPSHOT_VALIDATE_AFTER = 2147483647;
const API_PREFIXES = new Set(["session", "tasks", "internal", "uploads", "downloads"]);
const TOKEN_PREFIXES = new Set(["uploads", "downloads"]);

/** Upload and download tokens are credentials carried in the path; they never reach a log line. */
function loggablePath(segments: string[]): string {
  if (segments.length >= 2 && TOKEN_PREFIXES.has(segments[0]!)) return `/${segments[0]}/:token`;
  return `/${segments.join("/")}`;
}

function readSession(request: Request): BrowserSession | null {
  const cookies = parseCookies(request.headers.get("Cookie"));
  const token = cookies.get(SESSION_COOKIE);
  const scope = decodeScope(cookies.get(SCOPE_COOKIE));
  if (!token || !scope) return null;
  return { token, client_id: scope.client_id, task_id: scope.task_id };
}

function originAllowed(request: Request, env: EdgeEnv): boolean {
  const origin = request.headers.get("Origin");
  if (origin === null) return true;
  return origin === env.ALLOWED_ORIGIN;
}

function edgeKeyValid(request: Request, env: EdgeEnv): boolean {
  return secretsEqual(request.headers.get(EDGE_KEY_HEADER), env.EDGE_SERVICE_KEY);
}

function sessionExpired(): Response {
  const response = errorJson(401, "session_expired");
  for (const cookie of clearSessionCookies()) response.headers.append("Set-Cookie", cookie);
  return response;
}

/** Central router. No framework: a fixed set of paths, security headers on every response. */
export async function handleRequest(request: Request, env: EdgeEnv, ctx: ExecutionContext, deps: RouterDeps = {}): Promise<Response> {
  const url = new URL(request.url);
  const segments = url.pathname.split("/").filter((s) => s.length > 0);
  const isApi = segments[0] !== undefined && API_PREFIXES.has(segments[0]);
  let response: Response;
  try {
    response = await route(request, env, ctx, deps, url, segments);
  } catch (error) {
    console.error(`[buddy-edge] unhandled error on ${request.method} ${loggablePath(segments)}: ${String(error)}`);
    response = errorJson(500, "internal_error");
  }
  return applySecurityHeaders(response, { api: isApi });
}

async function route(request: Request, env: EdgeEnv, ctx: ExecutionContext, deps: RouterDeps, url: URL, segments: string[]): Promise<Response> {
  const method = request.method.toUpperCase();

  if (segments[0] === "session" && segments[1] === "exchange" && segments.length === 2) {
    if (method !== "POST") return errorJson(405, "method_not_allowed");
    return exchangeSession(request, env);
  }

  if (segments[0] === "tasks" && segments[1]) {
    const taskId = decodeURIComponent(segments[1]);
    if (segments.length === 2) {
      if (method !== "GET") return errorJson(405, "method_not_allowed");
      return taskSnapshot(request, env, ctx, taskId, url);
    }
    if (segments.length === 3 && segments[2] === "stream-tickets") {
      if (method !== "POST") return errorJson(405, "method_not_allowed");
      return streamTicket(request, env, taskId);
    }
    if (segments.length === 3 && segments[2] === "events") {
      if (method !== "GET") return errorJson(405, "method_not_allowed");
      return eventsSocket(request, env, taskId);
    }
    if (segments.length === 5 && segments[2] === "artifacts" && segments[4] === "summary") {
      if (method !== "GET") return errorJson(405, "method_not_allowed");
      return artifactSummary(request, env, taskId, decodeURIComponent(segments[3]!));
    }
    return errorJson(404, "not_found");
  }

  // Public by design: the signed token in the path is the only credential, so the edge key must exist to verify it.
  if (segments[0] === "uploads" && segments.length === 2) {
    if (!env.EDGE_SERVICE_KEY) return errorJson(503, "edge_key_not_configured");
    if (method !== "PUT") return errorJson(405, "method_not_allowed");
    return handleUpload(request, env, segments[1]!);
  }
  if (segments[0] === "downloads" && segments.length === 2) {
    if (!env.EDGE_SERVICE_KEY) return errorJson(503, "edge_key_not_configured");
    if (method !== "GET") return errorJson(405, "method_not_allowed");
    return handleDownload(env, segments[1]!);
  }

  if (segments[0] === "internal") {
    if (!env.EDGE_SERVICE_KEY) return errorJson(503, "edge_key_not_configured");
    if (!edgeKeyValid(request, env)) return errorJson(401, "edge_key_invalid");
    if (segments[1] === "captures" && segments.length === 2) {
      if (method !== "POST") return errorJson(405, "method_not_allowed");
      return handleCaptureRequest(request, env, deps.captureRunner);
    }
    if (segments[1] === "budget" && segments.length === 2) {
      if (method !== "GET") return errorJson(405, "method_not_allowed");
      const budget = budgetStub(env);
      const [report, reconciliation] = await Promise.all([budget.read(), budget.listReconciliation()]);
      return json({ ...report, reconciliation });
    }
    if (segments[1] === "events" && segments.length === 2) {
      if (method !== "POST") return errorJson(405, "method_not_allowed");
      return handleEventIngest(request, env);
    }
    if (segments[1] === "objects") {
      if (segments.length === 2) {
        if (method !== "DELETE") return errorJson(405, "method_not_allowed");
        return handleObjectDelete(env, url);
      }
      if (segments.length === 3 && segments[2] === "meta") {
        if (method !== "GET") return errorJson(405, "method_not_allowed");
        return handleObjectMeta(env, url);
      }
      if (segments.length === 3 && segments[2] === "content") {
        if (method !== "GET") return errorJson(405, "method_not_allowed");
        return handleObjectContent(env, url);
      }
      if (segments.length === 3 && segments[2] === "copy") {
        if (method !== "POST") return errorJson(405, "method_not_allowed");
        return handleObjectCopy(request, env);
      }
    }
    return errorJson(404, "not_found");
  }

  if (method !== "GET" && method !== "HEAD") return errorJson(405, "method_not_allowed");
  return env.ASSETS.fetch(request);
}

async function exchangeSession(request: Request, env: EdgeEnv): Promise<Response> {
  if (!originAllowed(request, env)) return errorJson(403, "origin_not_allowed");
  const body = await readJsonBody<{ ticket?: unknown }>(request);
  if (!body || typeof body.ticket !== "string" || body.ticket.length === 0 || body.ticket.length > 512) {
    return errorJson(400, "ticket_required");
  }
  const origin = request.headers.get("Origin") ?? env.ALLOWED_ORIGIN;
  const result = await azureClient(env).exchangeSession(body.ticket, origin);
  if (!result.ok) {
    if (result.status === 410) return errorJson(410, "ticket_invalid");
    if (result.status === 404) return errorJson(404, "feature_disabled");
    if (result.status === 401 || result.status === 403) return errorJson(403, "exchange_denied");
    return errorJson(502, "azure_unavailable");
  }
  const session = result.data;
  const expiresMs = Date.parse(session.expires_at);
  const maxAge = Number.isNaN(expiresMs) ? 300 : Math.floor((expiresMs - Date.now()) / 1000);
  // The token lives only in the HttpOnly cookie; the body carries no secret.
  const response = json({ task_id: session.task_id, expires_at: session.expires_at }, 201);
  for (const cookie of sessionCookies(session.session_token, { client_id: session.client_id, task_id: session.task_id }, maxAge)) {
    response.headers.append("Set-Cookie", cookie);
  }
  return response;
}

async function taskSnapshot(request: Request, env: EdgeEnv, ctx: ExecutionContext, taskId: string, url: URL): Promise<Response> {
  const session = readSession(request);
  if (!session) return errorJson(401, "session_required");
  if (session.task_id !== taskId) return errorJson(403, "task_scope_mismatch");
  const afterRaw = Number.parseInt(url.searchParams.get("after") ?? "0", 10);
  const after = Number.isFinite(afterRaw) && afterRaw >= 0 ? afterRaw : 0;

  const result = await azureClient(env).snapshot(taskId, session.token, after);
  if (!result.ok) {
    if (result.status === 401) return sessionExpired();
    if (result.status === 404) return errorJson(404, "task_not_found");
    return errorJson(502, "azure_unavailable");
  }
  const snapshot = result.data;
  const observedAt = new Date().toISOString();

  // Let the projection catch up in the background; the response never waits on the Durable Object.
  const projection = progressStub(env, session.client_id, taskId);
  ctx.waitUntil(
    projection.reconcile({ progress: snapshot.progress, events: snapshot.events }).catch((error) => {
      console.warn(`[buddy-edge] reconcile failed for ${taskId}: ${String(error)}`);
    }),
  );

  return json({
    task_id: snapshot.task_id,
    progress: snapshot.progress,
    events: snapshot.events,
    events_truncated: snapshot.events_truncated === true,
    observed_at: observedAt,
    session: { expires_at: snapshot.session?.expires_at ?? null },
  });
}

async function streamTicket(request: Request, env: EdgeEnv, taskId: string): Promise<Response> {
  if (!originAllowed(request, env)) return errorJson(403, "origin_not_allowed");
  const session = readSession(request);
  if (!session) return errorJson(401, "session_required");
  if (session.task_id !== taskId) return errorJson(403, "task_scope_mismatch");

  // Fresh authorization without a full history read.
  const check = await azureClient(env).snapshot(taskId, session.token, SNAPSHOT_VALIDATE_AFTER);
  if (!check.ok) {
    if (check.status === 401) return sessionExpired();
    if (check.status === 404) return errorJson(404, "task_not_found");
    return errorJson(502, "azure_unavailable");
  }
  const projection = progressStub(env, session.client_id, taskId);
  const ticket = await projection.mintTicket(session.client_id, taskId);
  await budgetStub(env).increment("sockets").catch(() => undefined);
  return json(ticket, 201);
}

async function eventsSocket(request: Request, env: EdgeEnv, taskId: string): Promise<Response> {
  if (request.headers.get("Upgrade")?.toLowerCase() !== "websocket") return errorJson(426, "websocket_required");
  const session = readSession(request);
  if (!session) return errorJson(401, "session_required");
  if (session.task_id !== taskId) return errorJson(403, "task_scope_mismatch");
  if (!new URL(request.url).searchParams.get("ticket")) return errorJson(400, "ticket_required");
  // The Durable Object validates and consumes the ticket, then accepts the socket with hibernation.
  const projection = progressStub(env, session.client_id, taskId);
  return projection.fetch(request);
}

async function artifactSummary(request: Request, env: EdgeEnv, taskId: string, artifactId: string): Promise<Response> {
  const session = readSession(request);
  if (!session) return errorJson(401, "session_required");
  if (session.task_id !== taskId) return errorJson(403, "task_scope_mismatch");
  const outcome = await getArtifactSummary(env, azureClient(env), {
    task_id: taskId,
    artifact_id: artifactId,
    client_id: session.client_id,
    session_token: session.token,
  });
  if (!outcome.ok) {
    if (outcome.status === 401) return sessionExpired();
    if (outcome.status === 404) return errorJson(404, "artifact_not_found");
    return errorJson(502, "azure_unavailable");
  }
  return json(outcome.summary, 200, { "X-Buddy-Cache": outcome.source });
}
