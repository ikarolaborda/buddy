import { EDGE_KEY_HEADER, EDGE_SESSION_HEADER } from "./env";

/** Additive progress fields returned by Azure for a task. */
export interface TaskProgress {
  status: string;
  phase?: string | null;
  phase_started_at?: string | null;
  phase_deadline_at?: string | null;
  queued_at?: string | null;
  worker_started_at?: string | null;
  heartbeat_at?: string | null;
  progress_sequence?: number | null;
  progress_observed_at?: string | null;
  next_poll_after_ms?: number | null;
  generation?: number | null;
  state_version?: number | null;
  recovery_task_id?: string | null;
  failure_category?: string | null;
}

export interface SessionExchangeResponse {
  session_token: string;
  task_id: string;
  client_id: string;
  scope: string[];
  expires_at: string;
  hard_expires_at: string;
}

export interface SnapshotResponse {
  task_id: string;
  progress: TaskProgress;
  events: unknown[];
  events_truncated: boolean;
  session: { expires_at: string };
}

export interface DelegationResponse {
  delegation: string;
  expires_at: string;
  generation: number;
}

export interface StatusResponse {
  task_id: string;
  progress: TaskProgress;
}

export type InterventionAction = "diagnose_health" | "recover_evaluation";

export interface InterventionRequest {
  delegation: string;
  request_id: string;
  action: InterventionAction;
  blocker: "operational_failure";
  context: {
    summary: string;
    session_id?: string;
    last_error?: string;
    attempted_actions?: string[];
  };
}

export interface InterventionResponse {
  intervention_id: string;
  action: InterventionAction;
  status: "running" | "completed" | "failed" | "dispatched" | "blocked";
  context: unknown;
  result: unknown;
}

export interface ArtifactSummaryResponse {
  artifact_id: string;
  task_id: string;
  type: string;
  media_type: string;
  size_bytes: number;
  content_hash: string;
  processor_version: string;
  processing_status: string;
  storage_status: string;
  summary?: unknown;
}

export interface CaptureCompletionRequest {
  callback_token: string;
  status: "completed" | "failed";
  result: {
    status_code: number | null;
    timing_ms: number;
    console_errors: string[];
    network_failures: string[];
    screenshot_object_key: string | null;
    error_code?: string;
  };
}

export type AzureResult<T> =
  | { ok: true; status: number; data: T }
  | { ok: false; status: number; error: string; data?: unknown };

export type FetchLike = (input: string, init?: RequestInit) => Promise<Response>;

/**
 * Thin client for the `/api/internal/cloudflare/` contract. Every call carries the edge service key;
 * browser-delegated reads additionally carry the session token. Nothing here caches authorization.
 */
export class AzureClient {
  constructor(
    private readonly base: string,
    private readonly serviceKey: string,
    // Resolved at call time so test stubs installed on globalThis take effect.
    private readonly fetchImpl: FetchLike = (input, init) => globalThis.fetch(input, init),
  ) {}

  exchangeSession(ticket: string, origin: string): Promise<AzureResult<SessionExchangeResponse>> {
    return this.call<SessionExchangeResponse>("POST", "/api/internal/cloudflare/sessions/exchange", {
      body: { ticket, origin },
    });
  }

  snapshot(taskId: string, sessionToken: string, afterSequence: number): Promise<AzureResult<SnapshotResponse>> {
    const query = new URLSearchParams({ after_sequence: String(afterSequence) });
    return this.call<SnapshotResponse>("GET", `/api/internal/cloudflare/tasks/${encodeURIComponent(taskId)}/snapshot?${query}`, {
      headers: { [EDGE_SESSION_HEADER]: sessionToken },
    });
  }

  createDelegation(taskId: string, eventId: string, clientId: string): Promise<AzureResult<DelegationResponse>> {
    return this.call<DelegationResponse>("POST", `/api/internal/cloudflare/tasks/${encodeURIComponent(taskId)}/delegations`, {
      body: { event_id: eventId, client_id: clientId },
    });
  }

  status(taskId: string, delegation: string): Promise<AzureResult<StatusResponse>> {
    const query = new URLSearchParams({ delegation });
    return this.call<StatusResponse>("GET", `/api/internal/cloudflare/tasks/${encodeURIComponent(taskId)}/status?${query}`);
  }

  intervene(taskId: string, request: InterventionRequest, rawBody?: string): Promise<AzureResult<InterventionResponse>> {
    return this.call<InterventionResponse>("POST", `/api/internal/cloudflare/tasks/${encodeURIComponent(taskId)}/interventions`, {
      body: request,
      rawBody,
    });
  }

  artifactSummary(
    taskId: string,
    artifactId: string,
    sessionToken: string,
    metadataOnly: boolean,
  ): Promise<AzureResult<ArtifactSummaryResponse>> {
    const suffix = metadataOnly ? "?metadata_only=1" : "";
    return this.call<ArtifactSummaryResponse>(
      "GET",
      `/api/internal/cloudflare/tasks/${encodeURIComponent(taskId)}/artifacts/${encodeURIComponent(artifactId)}/summary${suffix}`,
      { headers: { [EDGE_SESSION_HEADER]: sessionToken } },
    );
  }

  completeCapture(taskId: string, captureId: string, request: CaptureCompletionRequest): Promise<AzureResult<unknown>> {
    return this.call<unknown>(
      "POST",
      `/api/internal/cloudflare/tasks/${encodeURIComponent(taskId)}/diagnostic-captures/${encodeURIComponent(captureId)}/complete`,
      { body: request },
    );
  }

  private async call<T>(
    method: "GET" | "POST",
    path: string,
    options: { body?: unknown; rawBody?: string; headers?: Record<string, string> } = {},
  ): Promise<AzureResult<T>> {
    const headers = new Headers(options.headers);
    headers.set(EDGE_KEY_HEADER, this.serviceKey);
    headers.set("Accept", "application/json");
    let body: string | undefined;
    if (options.rawBody !== undefined) {
      body = options.rawBody;
    } else if (options.body !== undefined) {
      body = JSON.stringify(options.body);
    }
    if (body !== undefined) headers.set("Content-Type", "application/json");

    let response: Response;
    try {
      response = await this.fetchImpl(`${this.base}${path}`, { method, headers, body });
    } catch {
      return { ok: false, status: 0, error: "azure_unreachable" };
    }

    const text = await response.text();
    let data: unknown = null;
    if (text.length > 0) {
      try {
        data = JSON.parse(text);
      } catch {
        return { ok: false, status: response.status, error: "azure_invalid_response" };
      }
    }
    if (response.ok) return { ok: true, status: response.status, data: data as T };
    const error =
      data !== null && typeof data === "object" && typeof (data as { error?: unknown }).error === "string"
        ? ((data as { error: string }).error)
        : `http_${response.status}`;
    return { ok: false, status: response.status, error, data };
  }
}

export function azureClient(env: { AZURE_API_BASE: string; EDGE_SERVICE_KEY?: string }): AzureClient {
  return new AzureClient(env.AZURE_API_BASE.replace(/\/+$/, ""), env.EDGE_SERVICE_KEY ?? "");
}
