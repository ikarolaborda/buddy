import { azureClient, type CaptureCompletionRequest } from "./azure";
import { evaluateCapturePolicy, type CapturePolicy } from "./captures/policy";
import { MAX_CAPTURE_MS, PuppeteerCaptureRunner, type CaptureRunner } from "./captures/runner";
import { flagEnabled, type EdgeEnv } from "./env";
import { errorJson, json, readJsonBody } from "./http";
import { budgetStub } from "./stubs";

export interface CaptureRequestBody {
  capture_id: string;
  task_id: string;
  client_id: string;
  url: string;
  policy: CapturePolicy;
  capture_seconds: number;
  screenshot_key: string;
  callback_token: string;
}

function isString(value: unknown): value is string {
  return typeof value === "string" && value.length > 0 && value.length <= 512;
}

export function validateCaptureBody(body: unknown): { ok: true; value: CaptureRequestBody } | { ok: false; reason: string } {
  if (body === null || typeof body !== "object") return { ok: false, reason: "body_invalid" };
  const c = body as Record<string, unknown>;
  for (const field of ["capture_id", "task_id", "client_id", "url", "screenshot_key", "callback_token"] as const) {
    if (!isString(c[field])) return { ok: false, reason: `missing_${field}` };
  }
  if (!/^[A-Za-z0-9._\-\/]+$/.test(c.screenshot_key as string) || (c.screenshot_key as string).includes("..")) {
    return { ok: false, reason: "invalid_screenshot_key" };
  }
  const policy = c.policy as Record<string, unknown> | undefined;
  if (!policy || typeof policy !== "object" || !Array.isArray(policy.allowed_hosts) || !policy.allowed_hosts.every((h) => typeof h === "string")) {
    return { ok: false, reason: "invalid_policy" };
  }
  const seconds = typeof c.capture_seconds === "number" && Number.isFinite(c.capture_seconds) ? c.capture_seconds : 30;
  return {
    ok: true,
    value: {
      capture_id: c.capture_id as string,
      task_id: c.task_id as string,
      client_id: c.client_id as string,
      url: c.url as string,
      policy: {
        allowed_hosts: policy.allowed_hosts as string[],
        allow_subresources_same_host: policy.allow_subresources_same_host === true,
        redirects_allowed: policy.redirects_allowed === true,
      },
      capture_seconds: Math.max(1, Math.min(30, Math.floor(seconds))),
      screenshot_key: c.screenshot_key as string,
      callback_token: c.callback_token as string,
    },
  };
}

/**
 * POST /internal/captures. Gated by BROWSER_DIAGNOSTICS_ENABLED (default off), the daily capture budget and a
 * global concurrency limit of two. Policy is enforced before any browser session exists. The completion callback
 * to Azure is sent for every outcome that got past validation.
 */
export async function handleCaptureRequest(request: Request, env: EdgeEnv, runner?: CaptureRunner): Promise<Response> {
  if (!flagEnabled(env, "BROWSER_DIAGNOSTICS_ENABLED")) {
    return errorJson(503, "browser_diagnostics_disabled");
  }
  const body = await readJsonBody<unknown>(request);
  const validation = validateCaptureBody(body);
  if (!validation.ok) return errorJson(400, "invalid_capture_request", { reason: validation.reason });
  const capture = validation.value;

  const policy = evaluateCapturePolicy(capture.url, capture.policy);
  if (!policy.ok) {
    return errorJson(422, "capture_policy_rejected", { reason: policy.reason, capture_id: capture.capture_id });
  }

  const budget = budgetStub(env);
  const daily = await budget.increment("captures");
  if (!daily.allowed) {
    return errorJson(429, "capture_budget_exhausted", { count: daily.count, limit: daily.limit });
  }
  const slot = await budget.acquireCaptureSlot();
  if (!slot.acquired) {
    return errorJson(429, "capture_concurrency_limit", { active: slot.active, limit: slot.limit });
  }

  const activeRunner = runner ?? new PuppeteerCaptureRunner(env.BROWSER);
  let completion: CaptureCompletionRequest;
  try {
    const result = await activeRunner.run({
      capture_id: capture.capture_id,
      url: policy.url,
      host: policy.host,
      policy: capture.policy,
      timeout_ms: Math.min(capture.capture_seconds * 1000, MAX_CAPTURE_MS),
    });
    let screenshotKey: string | null = null;
    if (result.screenshot && result.screenshot.byteLength > 0) {
      await env.BUDDY_ARTIFACTS.put(capture.screenshot_key, result.screenshot, {
        httpMetadata: { contentType: "image/png" },
        customMetadata: { capture_id: capture.capture_id, task_id: capture.task_id, client_id: capture.client_id },
      });
      screenshotKey = capture.screenshot_key;
    }
    completion = {
      callback_token: capture.callback_token,
      status: result.error_code ? "failed" : "completed",
      result: {
        status_code: result.status_code,
        timing_ms: result.timing_ms,
        console_errors: result.console_errors.slice(0, 20),
        network_failures: result.network_failures.slice(0, 20),
        screenshot_object_key: screenshotKey,
        ...(result.error_code ? { error_code: result.error_code } : {}),
      },
    };
  } catch (error) {
    completion = {
      callback_token: capture.callback_token,
      status: "failed",
      result: {
        status_code: null,
        timing_ms: 0,
        console_errors: [],
        network_failures: [],
        screenshot_object_key: null,
        error_code: error instanceof Error && error.message === "capture_timeout" ? "timeout" : "runner_failed",
      },
    };
  } finally {
    await budget.releaseCaptureSlot();
  }

  const callback = await azureClient(env).completeCapture(capture.task_id, capture.capture_id, completion);
  return json(
    {
      capture_id: capture.capture_id,
      status: completion.status,
      result: completion.result,
      callback: callback.ok ? "delivered" : `failed:${callback.status}:${callback.error}`,
    },
    200,
  );
}
