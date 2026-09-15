import { afterEach, describe, expect, it } from "vitest";
import { handleCaptureRequest } from "../src/captures";
import { evaluateCapturePolicy, redactText, redactUrl, requestAllowed, type CapturePolicy } from "../src/captures/policy";
import { MockCaptureRunner } from "../src/captures/runner";
import { budgetStubFor, env, installAzureStub, makeEnv, type AzureStub } from "./helpers";

const POLICY: CapturePolicy = { allowed_hosts: ["status.example.com"], allow_subresources_same_host: false, redirects_allowed: false };

describe("capture policy", () => {
  it("accepts an exact allowlisted https host", () => {
    const check = evaluateCapturePolicy("https://status.example.com/health?x=1", POLICY);
    expect(check.ok).toBe(true);
    if (check.ok) expect(check.host).toBe("status.example.com");
    expect(evaluateCapturePolicy("http://STATUS.example.com./", POLICY).ok).toBe(true);
  });

  it.each([
    ["ftp://status.example.com/", "scheme_not_allowed"],
    ["javascript:alert(1)", "scheme_not_allowed"],
    ["https://user:pw@status.example.com/", "userinfo_not_allowed"],
    ["https://10.0.0.5/", "ip_literal_not_allowed"],
    ["https://169.254.169.254/latest/meta-data", "ip_literal_not_allowed"],
    ["https://[::1]/", "ip_literal_not_allowed"],
    ["https://[fd00::1]/", "ip_literal_not_allowed"],
    ["https://xn--e1awd7f.example.com/", "punycode_not_allowed"],
    ["https://localhost/", "localhost_denied"],
    ["https://app.localhost/", "localhost_denied"],
    ["https://metadata.google.internal/computeMetadata/v1/", "metadata_host_denied"],
    ["https://service.internal/", "private_host_denied"],
    ["https://printer.local/", "private_host_denied"],
    ["https://intranet/", "private_host_denied"],
    ["https://other.example.com/", "host_not_allowed"],
    ["https://status.example.com.evil.example/", "host_not_allowed"],
    ["not a url", "url_invalid"],
  ])("rejects %s (%s)", (url, reason) => {
    expect(evaluateCapturePolicy(url, POLICY)).toEqual({ ok: false, reason });
  });

  it("rejects allowlists that contain wildcards, IPs or nothing", () => {
    expect(evaluateCapturePolicy("https://status.example.com/", { ...POLICY, allowed_hosts: [] })).toEqual({ ok: false, reason: "allowlist_invalid" });
    expect(evaluateCapturePolicy("https://status.example.com/", { ...POLICY, allowed_hosts: ["*.example.com"] })).toEqual({ ok: false, reason: "allowlist_invalid" });
    expect(evaluateCapturePolicy("https://status.example.com/", { ...POLICY, allowed_hosts: ["10.0.0.1"] })).toEqual({ ok: false, reason: "allowlist_invalid" });
  });

  it("gates redirects and subresources during interception", () => {
    expect(requestAllowed("https://status.example.com/", "status.example.com", POLICY, { isDocument: true, isRedirect: false })).toBe(true);
    expect(requestAllowed("https://status.example.com/app.js", "status.example.com", POLICY, { isDocument: false, isRedirect: false })).toBe(false);
    expect(requestAllowed("https://status.example.com/app.js", "status.example.com", { ...POLICY, allow_subresources_same_host: true }, { isDocument: false, isRedirect: false })).toBe(true);
    expect(requestAllowed("https://status.example.com/next", "status.example.com", POLICY, { isDocument: true, isRedirect: true })).toBe(false);
    expect(requestAllowed("https://status.example.com/next", "status.example.com", { ...POLICY, redirects_allowed: true }, { isDocument: true, isRedirect: true })).toBe(true);
    expect(requestAllowed("https://cdn.example.com/x.js", "status.example.com", { ...POLICY, allow_subresources_same_host: true }, { isDocument: false, isRedirect: false })).toBe(false);
    expect(requestAllowed("ws://status.example.com/socket", "status.example.com", POLICY, { isDocument: true, isRedirect: false })).toBe(false);
  });

  it("redacts query strings and credential material from evidence", () => {
    expect(redactUrl("https://status.example.com/p?token=abc#frag")).toBe("https://status.example.com/p");
    expect(redactUrl("https://u:p@status.example.com/p")).toBe("https://status.example.com/p");
    expect(redactText("Authorization: Bearer abc.def cookie=zzz; other")).not.toMatch(/abc\.def|zzz/);
    expect(redactText("x".repeat(600)).length).toBe(500);
  });
});

describe("POST /internal/captures", () => {
  let stub: AzureStub | null = null;
  afterEach(() => {
    stub?.restore();
    stub = null;
  });

  function captureRequest(body: Record<string, unknown> = {}): Request {
    return new Request("https://edge.test/internal/captures", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Buddy-Edge-Key": "test-edge-service-key" },
      body: JSON.stringify({
        capture_id: "cap-1",
        task_id: "task-1",
        client_id: "client-a",
        url: "https://status.example.com/health",
        policy: POLICY,
        capture_seconds: 45,
        screenshot_key: "captures/client-a/task-1/cap-1.png",
        callback_token: "cb-token",
        ...body,
      }),
    });
  }

  it("does nothing while BROWSER_DIAGNOSTICS_ENABLED is false", async () => {
    stub = installAzureStub({});
    const runner = new MockCaptureRunner();
    const response = await handleCaptureRequest(captureRequest(), env, runner);
    expect(response.status).toBe(503);
    expect(await response.json()).toEqual({ error: "browser_diagnostics_disabled" });
    expect(runner.calls).toHaveLength(0);
    expect(stub.calls).toHaveLength(0);
  });

  it("rejects forbidden targets before any browser session and sends no callback", async () => {
    stub = installAzureStub({});
    const runner = new MockCaptureRunner();
    const enabled = makeEnv({ BROWSER_DIAGNOSTICS_ENABLED: "true" });
    const response = await handleCaptureRequest(captureRequest({ url: "https://169.254.169.254/" }), enabled, runner);
    expect(response.status).toBe(422);
    expect(await response.json()).toMatchObject({ error: "capture_policy_rejected", reason: "ip_literal_not_allowed" });
    expect(runner.calls).toHaveLength(0);
    expect(stub.calls).toHaveLength(0);
    expect((await budgetStubFor().read()).counters.captures.count).toBe(0);
  });

  it("runs the capture, stores the screenshot in R2 and completes the callback", async () => {
    stub = installAzureStub({
      "POST /api/internal/cloudflare/tasks/task-1/diagnostic-captures/cap-1/complete": () => Response.json({ ok: true }),
    });
    const runner = new MockCaptureRunner({ result: { console_errors: ["TypeError: boom"], network_failures: ["blocked https://status.example.com/x.js"] } });
    const enabled = makeEnv({ BROWSER_DIAGNOSTICS_ENABLED: "true" });
    const response = await handleCaptureRequest(captureRequest(), enabled, runner);
    expect(response.status).toBe(200);
    expect(await response.json()).toMatchObject({ capture_id: "cap-1", status: "completed", callback: "delivered" });

    expect(runner.calls).toHaveLength(1);
    expect(runner.calls[0]!.timeout_ms).toBe(30_000);
    expect(runner.calls[0]!.host).toBe("status.example.com");

    const object = await env.BUDDY_ARTIFACTS.get("captures/client-a/task-1/cap-1.png");
    expect(object).not.toBeNull();
    expect(object!.httpMetadata?.contentType).toBe("image/png");

    const callback = stub.calls[0]!;
    expect(callback.headers.get("X-Buddy-Edge-Key")).toBe("test-edge-service-key");
    expect(JSON.parse(callback.body!)).toEqual({
      callback_token: "cb-token",
      status: "completed",
      result: {
        status_code: 200,
        timing_ms: 42,
        console_errors: ["TypeError: boom"],
        network_failures: ["blocked https://status.example.com/x.js"],
        screenshot_object_key: "captures/client-a/task-1/cap-1.png",
      },
    });
    const report = await budgetStubFor().read();
    expect(report.counters.captures.count).toBe(1);
    expect(report.active_captures).toBe(0);
  });

  it("reports runner failures as failed captures and still releases the slot", async () => {
    stub = installAzureStub({
      "POST /api/internal/cloudflare/tasks/task-1/diagnostic-captures/cap-1/complete": () => Response.json({ ok: true }),
    });
    const runner = new MockCaptureRunner({ fail: new Error("capture_timeout") });
    const response = await handleCaptureRequest(captureRequest(), makeEnv({ BROWSER_DIAGNOSTICS_ENABLED: "true" }), runner);
    expect(await response.json()).toMatchObject({ status: "failed", result: { error_code: "timeout", screenshot_object_key: null } });
    expect((await budgetStubFor().read()).active_captures).toBe(0);
  });

  it("enforces two concurrent captures and the daily budget", async () => {
    stub = installAzureStub({});
    const budget = budgetStubFor();
    await budget.acquireCaptureSlot();
    await budget.acquireCaptureSlot();
    const runner = new MockCaptureRunner();
    const enabled = makeEnv({ BROWSER_DIAGNOSTICS_ENABLED: "true" });
    const busy = await handleCaptureRequest(captureRequest(), enabled, runner);
    expect(busy.status).toBe(429);
    expect(await busy.json()).toMatchObject({ error: "capture_concurrency_limit", active: 2, limit: 2 });
    expect(runner.calls).toHaveLength(0);
    await budget.releaseCaptureSlot();
    await budget.releaseCaptureSlot();

    for (let i = 0; i < 9; i++) await budget.increment("captures");
    const exhausted = await handleCaptureRequest(captureRequest(), enabled, runner);
    expect(exhausted.status).toBe(429);
    expect(await exhausted.json()).toMatchObject({ error: "capture_budget_exhausted", limit: 10 });
    expect(runner.calls).toHaveLength(0);
  });
});
