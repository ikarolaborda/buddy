import puppeteer, { type Browser } from "@cloudflare/puppeteer";
import { redactText, redactUrl, requestAllowed, type CapturePolicy } from "./policy";

export const MAX_CAPTURE_MS = 30_000;
export const MAX_LOG_ENTRIES = 20;
export const MAX_LOG_CHARS = 500;

export interface CaptureRun {
  capture_id: string;
  url: URL;
  host: string;
  policy: CapturePolicy;
  timeout_ms: number;
}

export interface CaptureRunResult {
  status_code: number | null;
  timing_ms: number;
  console_errors: string[];
  network_failures: string[];
  /** PNG bytes, or null when the capture failed before a screenshot could be taken. */
  screenshot: Uint8Array | null;
  error_code?: string;
}

export interface CaptureRunner {
  run(input: CaptureRun): Promise<CaptureRunResult>;
}

export interface MockRunnerOptions {
  result?: Partial<CaptureRunResult>;
  fail?: Error;
}

/** Deterministic runner for tests: records calls and returns a canned result. */
export class MockCaptureRunner implements CaptureRunner {
  readonly calls: CaptureRun[] = [];

  constructor(private readonly options: MockRunnerOptions = {}) {}

  async run(input: CaptureRun): Promise<CaptureRunResult> {
    this.calls.push(input);
    if (this.options.fail) throw this.options.fail;
    return {
      status_code: 200,
      timing_ms: 42,
      console_errors: [],
      network_failures: [],
      screenshot: new Uint8Array([0x89, 0x50, 0x4e, 0x47]),
      ...this.options.result,
    };
  }
}

function pushBounded(list: string[], value: string): void {
  if (list.length >= MAX_LOG_ENTRIES) return;
  list.push(redactText(value, MAX_LOG_CHARS));
}

/**
 * Real runner on Browser Run through @cloudflare/puppeteer. Session guardrails carry an explicit domain policy
 * (never omitted), request interception aborts anything outside the policy before it leaves the browser,
 * downloads are denied, no caller script is ever injected, and the session is always closed.
 */
export class PuppeteerCaptureRunner implements CaptureRunner {
  constructor(private readonly browserBinding: BrowserRun) {}

  async run(input: CaptureRun): Promise<CaptureRunResult> {
    const timeoutMs = Math.min(MAX_CAPTURE_MS, Math.max(1_000, input.timeout_ms));
    const consoleErrors: string[] = [];
    const networkFailures: string[] = [];
    const started = Date.now();
    let browser: Browser | null = null;
    let statusCode: number | null = null;
    let screenshot: Uint8Array | null = null;
    let errorCode: string | undefined;

    const deadline = new Promise<never>((_, reject) => {
      setTimeout(() => reject(new Error("capture_timeout")), timeoutMs);
    });

    try {
      browser = await puppeteer.launch(this.browserBinding as unknown as Parameters<typeof puppeteer.launch>[0], {
        keep_alive: timeoutMs,
        guardrails: { allowedDomains: [input.host] },
      });
      const page = await browser.newPage();
      page.setDefaultNavigationTimeout(timeoutMs);
      page.setDefaultTimeout(timeoutMs);

      try {
        const session = await page.createCDPSession();
        await session.send("Browser.setDownloadBehavior", { behavior: "deny" });
      } catch {
        // Older protocol versions: downloads are still blocked by interception of non-document responses.
      }

      await page.setRequestInterception(true);
      page.on("request", (request) => {
        const isDocument = request.resourceType() === "document" && request.frame() === page.mainFrame();
        const isRedirect = request.redirectChain().length > 0;
        if (requestAllowed(request.url(), input.host, input.policy, { isDocument, isRedirect })) {
          void request.continue();
        } else {
          pushBounded(networkFailures, `blocked ${redactUrl(request.url())}`);
          void request.abort("blockedbyclient");
        }
      });
      page.on("console", (message) => {
        if (message.type() === "error") pushBounded(consoleErrors, message.text());
      });
      page.on("pageerror", (error) => {
        pushBounded(consoleErrors, error instanceof Error ? error.message : String(error));
      });
      page.on("requestfailed", (request) => {
        const reason = request.failure()?.errorText ?? "failed";
        if (reason !== "net::ERR_BLOCKED_BY_CLIENT") pushBounded(networkFailures, `${reason} ${redactUrl(request.url())}`);
      });

      const response = await Promise.race([page.goto(input.url.toString(), { waitUntil: "load", timeout: timeoutMs }), deadline]);
      statusCode = response?.status() ?? null;
      const image = await Promise.race([page.screenshot({ type: "png", fullPage: false }), deadline]);
      screenshot = image instanceof Uint8Array ? image : new Uint8Array(image as ArrayBufferLike);
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      errorCode = message === "capture_timeout" ? "timeout" : "navigation_failed";
      pushBounded(networkFailures, message);
    } finally {
      if (browser) {
        try {
          await browser.close();
        } catch {
          // Session already gone.
        }
      }
    }

    return {
      status_code: statusCode,
      timing_ms: Date.now() - started,
      console_errors: consoleErrors,
      network_failures: networkFailures,
      screenshot,
      ...(errorCode ? { error_code: errorCode } : {}),
    };
  }
}
