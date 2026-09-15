import { createExecutionContext, SELF } from "cloudflare:test";
import { describe, expect, it } from "vitest";
import { CONTENT_SECURITY_POLICY } from "../src/http";
import { sanitizeFilename, verifySignedToken, type DownloadTokenPayload, type UploadTokenPayload } from "../src/objects";
import { handleRequest } from "../src/router";
import { currentClientId, downloadPayload, EDGE_KEY, env, FAR_FUTURE_EXP, internalHeaders, makeEnv, signEdgeToken, uploadPayload } from "./helpers";

// Produced with Node's `crypto.createHmac` (scratch script, secret "test-edge-service-key") to check the
// Worker verifier against an implementation that shares nothing with it.
const KAT_UPLOAD =
  "bup1.eyJrZXkiOiJjbGllbnRzL2FjbWUvdGFzay0xL2thdC5iaW4iLCJtYXhfYnl0ZXMiOjEwMjQsImNvbnRlbnRfdHlwZSI6ImFwcGxpY2F0aW9uL29jdGV0LXN0cmVhbSIsImV4cCI6NDEwMjQ0NDgwMCwidXBsb2FkX2lkIjoidXAta2F0In0.YoVwHEwoLn4CoxgU9xoAImHw6sLEeb_fubDuxukoMt4";
const KAT_DOWNLOAD =
  "bdl1.eyJrZXkiOiJjbGllbnRzL2FjbWUvdGFzay0xL2thdC5iaW4iLCJmaWxlbmFtZSI6ImthdC5iaW4iLCJjb250ZW50X3R5cGUiOiJhcHBsaWNhdGlvbi9vY3RldC1zdHJlYW0iLCJleHAiOjQxMDI0NDQ4MDB9.MP7iQPDIsPVCqZrfgFD8VBEJ6BXUmNFM6o1Fjq6MFIA";

async function signSegment(kind: string, segment: string, secret = EDGE_KEY): Promise<string> {
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey("raw", encoder.encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const signature = new Uint8Array(await crypto.subtle.sign("HMAC", key, encoder.encode(segment)));
  let binary = "";
  for (const byte of signature) binary += String.fromCharCode(byte);
  return `${kind}.${segment}.${btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "")}`;
}

function nowSeconds(): number {
  return Math.floor(Date.now() / 1000);
}

function objectKey(name = "report.txt"): string {
  return `clients/${currentClientId()}/task-1/${name}`;
}

function chunkedBody(chunks: number, chunkSize: number): ReadableStream<Uint8Array> {
  let sent = 0;
  return new ReadableStream<Uint8Array>({
    pull(controller) {
      if (sent >= chunks) {
        controller.close();
        return;
      }
      controller.enqueue(new Uint8Array(chunkSize).fill(0x61));
      sent += 1;
    },
  });
}

describe("signed token verification", () => {
  it("accepts upload and download tokens signed with the edge key", async () => {
    const upload = await verifySignedToken(await signEdgeToken("bup1", uploadPayload()), "bup1", EDGE_KEY);
    expect(upload).toEqual({ ok: true, payload: uploadPayload() });
    const download = await verifySignedToken(await signEdgeToken("bdl1", downloadPayload()), "bdl1", EDGE_KEY);
    expect(download).toEqual({ ok: true, payload: downloadPayload() });
  });

  it("verifies tokens produced by an independent HMAC implementation", async () => {
    const upload = await verifySignedToken(KAT_UPLOAD, "bup1", EDGE_KEY);
    expect(upload.ok).toBe(true);
    if (upload.ok) expect(upload.payload).toEqual({ key: "clients/acme/task-1/kat.bin", max_bytes: 1024, content_type: "application/octet-stream", exp: FAR_FUTURE_EXP, upload_id: "up-kat" });
    const download = await verifySignedToken(KAT_DOWNLOAD, "bdl1", EDGE_KEY);
    expect(download.ok).toBe(true);
    if (download.ok) expect(download.payload).toEqual({ key: "clients/acme/task-1/kat.bin", filename: "kat.bin", content_type: "application/octet-stream", exp: FAR_FUTURE_EXP });
  });

  it("rejects tampered payloads and foreign signatures", async () => {
    const genuine = await signEdgeToken("bup1", uploadPayload());
    const other = await signEdgeToken("bup1", uploadPayload({ max_bytes: 1 << 30 }));
    const [kind, , signature] = genuine.split(".") as [string, string, string];
    const [, otherPayload] = other.split(".") as [string, string, string];
    expect(await verifySignedToken(`${kind}.${otherPayload}.${signature}`, "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "signature_invalid" });
    expect(await verifySignedToken(await signEdgeToken("bup1", uploadPayload(), "another-secret"), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "signature_invalid" });
    expect(await verifySignedToken(genuine, "bup1", "another-secret")).toEqual({ ok: false, reason: "signature_invalid" });
    expect(await verifySignedToken(genuine, "bup1", undefined)).toEqual({ ok: false, reason: "signature_invalid" });
  });

  it("rejects expired tokens, including exp equal to now", async () => {
    expect(await verifySignedToken(await signEdgeToken("bup1", uploadPayload({ exp: nowSeconds() - 1 })), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "expired" });
    const now = nowSeconds();
    expect(await verifySignedToken(await signEdgeToken("bdl1", downloadPayload({ exp: now })), "bdl1", EDGE_KEY, now * 1000)).toEqual({ ok: false, reason: "expired" });
    expect((await verifySignedToken(await signEdgeToken("bdl1", downloadPayload({ exp: now + 1 })), "bdl1", EDGE_KEY, now * 1000)).ok).toBe(true);
  });

  it("rejects a token presented for the wrong kind", async () => {
    expect(await verifySignedToken(await signEdgeToken("bdl1", downloadPayload()), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "kind_mismatch" });
    expect(await verifySignedToken(await signEdgeToken("bup1", uploadPayload()), "bdl1", EDGE_KEY)).toEqual({ ok: false, reason: "kind_mismatch" });
    expect(await verifySignedToken(await signEdgeToken("bxx1", uploadPayload()), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "kind_mismatch" });
  });

  it("rejects a relabelled token: the signature still verifies, the payload shape does not", async () => {
    // The HMAC covers the payload segment only, so swapping the kind prefix keeps the signature valid.
    const relabelledUpload = await signEdgeToken("bdl1", uploadPayload());
    expect(await verifySignedToken(relabelledUpload, "bdl1", EDGE_KEY)).toEqual({ ok: false, reason: "payload_invalid" });
    const relabelledDownload = await signEdgeToken("bup1", downloadPayload());
    expect(await verifySignedToken(relabelledDownload, "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "payload_invalid" });
  });

  it("rejects keys outside clients/", async () => {
    for (const key of ["captures/x/y.png", "clients", "clients/", "/clients/x", "../clients/x", "Clients/x"]) {
      expect(await verifySignedToken(await signEdgeToken("bup1", uploadPayload({ key })), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "key_not_allowed" });
    }
  });

  it("rejects malformed tokens and payloads", async () => {
    expect(await verifySignedToken("", "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "malformed" });
    expect(await verifySignedToken("bup1.abc", "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "malformed" });
    expect(await verifySignedToken("bup1.a!b.c", "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "malformed" });
    expect(await verifySignedToken(await signSegment("bup1", btoa("not json").replace(/=+$/, "")), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "malformed" });
    expect(await verifySignedToken(await signSegment("bup1", btoa("[1]").replace(/=+$/, "")), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "payload_invalid" });
    const invalid: Record<string, unknown>[] = [
      { max_bytes: 0 },
      { max_bytes: "10" },
      { max_bytes: 1.5 },
      { exp: 1.5 },
      { exp: "4102444800" },
      { content_type: "not a media type" },
      { content_type: "text/plain\r\nX-Injected: 1" },
      { upload_id: "" },
      { upload_id: 7 },
      { key: 12 },
    ];
    for (const overrides of invalid) {
      expect(await verifySignedToken(await signEdgeToken("bup1", uploadPayload(overrides)), "bup1", EDGE_KEY)).toEqual({ ok: false, reason: "payload_invalid" });
    }
    expect(await verifySignedToken(await signEdgeToken("bdl1", downloadPayload({ filename: 3 })), "bdl1", EDGE_KEY)).toEqual({ ok: false, reason: "payload_invalid" });
  });
});

describe("PUT /uploads/:token", () => {
  async function uploadUrl(overrides: Record<string, unknown> = {}): Promise<{ url: string; token: string; payload: UploadTokenPayload }> {
    const payload = uploadPayload(overrides) as unknown as UploadTokenPayload;
    const token = await signEdgeToken("bup1", payload as unknown as Record<string, unknown>);
    return { url: `https://edge.test/uploads/${token}`, token, payload };
  }

  it("answers 413 when Content-Length exceeds max_bytes without reading the body", async () => {
    const { url, token, payload } = await uploadUrl({ max_bytes: 16 });
    const response = await SELF.fetch(url, { method: "PUT", body: "x".repeat(17) });
    expect(response.status).toBe(413);
    const text = await response.text();
    expect(JSON.parse(text)).toEqual({ error: "upload_too_large", max_bytes: 16 });
    expect(text).not.toContain(token);
    expect(await env.BUDDY_ARTIFACTS.head(payload.key)).toBeNull();
  });

  it("answers 413 when a chunked body overflows max_bytes mid-stream", async () => {
    const { url, payload } = await uploadUrl({ max_bytes: 3000 });
    const response = await SELF.fetch(url, { method: "PUT", body: chunkedBody(5, 1024) });
    expect(response.status).toBe(413);
    expect(await response.json()).toEqual({ error: "upload_too_large", max_bytes: 3000 });
    expect(await env.BUDDY_ARTIFACTS.head(payload.key)).toBeNull();
  });

  it("stores a body with Content-Length under the key with the content type and upload_id", async () => {
    const { url, token, payload } = await uploadUrl({ content_type: "text/plain; charset=utf-8", upload_id: "report.txt" });
    const response = await SELF.fetch(url, { method: "PUT", body: "hello world" });
    expect(response.status).toBe(201);
    const text = await response.text();
    expect(text).not.toContain(token);
    const body = JSON.parse(text) as { key: string; size: number; etag: string };
    expect(body).toMatchObject({ key: payload.key, size: 11 });
    expect(response.headers.get("Content-Security-Policy")).toBe(CONTENT_SECURITY_POLICY);
    expect(response.headers.get("Cache-Control")).toBe("no-store");

    const stored = await env.BUDDY_ARTIFACTS.get(payload.key);
    expect(stored).not.toBeNull();
    expect(await stored!.text()).toBe("hello world");
    expect(stored!.httpMetadata?.contentType).toBe("text/plain; charset=utf-8");
    expect(stored!.customMetadata).toEqual({ upload_id: "report.txt" });
    expect(stored!.etag).toBe(body.etag);
  });

  it("stores a chunked body that stays within max_bytes", async () => {
    const { url, payload } = await uploadUrl({ max_bytes: 3000, content_type: "application/octet-stream" });
    const response = await SELF.fetch(url, { method: "PUT", body: chunkedBody(2, 1000) });
    expect(response.status).toBe(201);
    expect(await response.json()).toMatchObject({ key: payload.key, size: 2000 });
    const stored = await env.BUDDY_ARTIFACTS.head(payload.key);
    expect(stored?.size).toBe(2000);
    expect(stored?.httpMetadata?.contentType).toBe("application/octet-stream");
  });

  it("answers 401 for expired, relabelled, wrong-kind and malformed tokens", async () => {
    const expired = await uploadUrl({ exp: nowSeconds() - 5 });
    const relabelled = `https://edge.test/uploads/${await signEdgeToken("bup1", downloadPayload())}`;
    const wrongKind = `https://edge.test/uploads/${await signEdgeToken("bdl1", downloadPayload())}`;
    const cases: [string, string][] = [
      [expired.url, "expired"],
      [relabelled, "payload_invalid"],
      [wrongKind, "kind_mismatch"],
      ["https://edge.test/uploads/garbage", "malformed"],
    ];
    for (const [url, reason] of cases) {
      const response = await SELF.fetch(url, { method: "PUT", body: "data" });
      expect(response.status).toBe(401);
      expect(await response.json()).toEqual({ error: "token_invalid", reason });
    }
    expect(await env.BUDDY_ARTIFACTS.head(expired.payload.key)).toBeNull();
  });

  it("accepts PUT only and needs the edge key to be configured", async () => {
    const { url } = await uploadUrl();
    expect((await SELF.fetch(url, { method: "POST", body: "data" })).status).toBe(405);
    expect((await SELF.fetch(url)).status).toBe(405);
    const unconfigured = await handleRequest(new Request(url, { method: "PUT", body: "data" }), makeEnv({ EDGE_SERVICE_KEY: undefined }), createExecutionContext());
    expect(unconfigured.status).toBe(503);
  });
});

describe("GET /downloads/:token", () => {
  async function downloadUrl(overrides: Record<string, unknown> = {}): Promise<{ url: string; token: string; payload: DownloadTokenPayload }> {
    const payload = downloadPayload(overrides) as unknown as DownloadTokenPayload;
    const token = await signEdgeToken("bdl1", payload as unknown as Record<string, unknown>);
    return { url: `https://edge.test/downloads/${token}`, token, payload };
  }

  it("answers 404 when the object is missing", async () => {
    const { url } = await downloadUrl();
    const response = await SELF.fetch(url);
    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ error: "not_found" });
  });

  it("streams the object as an attachment with the content type from the token", async () => {
    await env.BUDDY_ARTIFACTS.put(objectKey(), "hello", { httpMetadata: { contentType: "application/json" } });
    const { url, token } = await downloadUrl({ content_type: "text/plain", filename: "report.txt" });
    const response = await SELF.fetch(url);
    expect(response.status).toBe(200);
    expect(response.headers.get("Content-Type")).toBe("text/plain");
    expect(response.headers.get("Content-Disposition")).toBe('attachment; filename="report.txt"');
    expect(response.headers.get("Content-Length")).toBe("5");
    expect(response.headers.get("Cache-Control")).toBe("no-store");
    expect(response.headers.get("X-Content-Type-Options")).toBe("nosniff");
    expect(response.headers.get("Content-Security-Policy")).toBe(CONTENT_SECURITY_POLICY);
    const text = await response.text();
    expect(text).toBe("hello");
    expect(text).not.toContain(token);
  });

  it("sanitizes the attachment file name", async () => {
    expect(sanitizeFilename("report.txt")).toBe("report.txt");
    expect(sanitizeFilename("../evil name?.txt")).toBe("_evil_name_.txt");
    expect(sanitizeFilename('a"b\r\nc.txt')).toBe("a_b_c.txt");
    expect(sanitizeFilename("x".repeat(200) + ".txt")).toHaveLength(120);
    expect(sanitizeFilename("???")).toBe("download");
    expect(sanitizeFilename("")).toBe("download");

    await env.BUDDY_ARTIFACTS.put(objectKey(), "hello");
    const { url } = await downloadUrl({ filename: '../evil "name"?.txt' });
    const response = await SELF.fetch(url);
    expect(response.status).toBe(200);
    expect(response.headers.get("Content-Disposition")).toBe('attachment; filename="_evil_name_.txt"');
  });

  it("answers 401 for expired, relabelled and wrong-kind tokens", async () => {
    await env.BUDDY_ARTIFACTS.put(objectKey(), "hello");
    const expired = await downloadUrl({ exp: nowSeconds() - 5 });
    const relabelled = `https://edge.test/downloads/${await signEdgeToken("bdl1", uploadPayload())}`;
    const wrongKind = `https://edge.test/downloads/${await signEdgeToken("bup1", uploadPayload())}`;
    const cases: [string, string][] = [
      [expired.url, "expired"],
      [relabelled, "payload_invalid"],
      [wrongKind, "kind_mismatch"],
    ];
    for (const [url, reason] of cases) {
      const response = await SELF.fetch(url);
      expect(response.status).toBe(401);
      expect(await response.json()).toEqual({ error: "token_invalid", reason });
    }
  });

  it("accepts GET only", async () => {
    const { url } = await downloadUrl();
    expect((await SELF.fetch(url, { method: "POST" })).status).toBe(405);
    expect((await SELF.fetch("https://edge.test/downloads")).status).toBe(404);
  });
});

describe("internal object API", () => {
  const base = "https://edge.test/internal/objects";

  function metaUrl(key: string): string {
    return `${base}/meta?key=${encodeURIComponent(key)}`;
  }

  it("requires the edge key on every route", async () => {
    const key = objectKey();
    const responses = await Promise.all([
      SELF.fetch(metaUrl(key)),
      SELF.fetch(`${base}/content?key=${encodeURIComponent(key)}`),
      SELF.fetch(`${base}/copy`, { method: "POST", body: JSON.stringify({ from: key, to: objectKey("copy.txt") }) }),
      SELF.fetch(`${base}?key=${encodeURIComponent(key)}`, { method: "DELETE" }),
    ]);
    for (const response of responses) {
      expect(response.status).toBe(401);
      expect(await response.json()).toEqual({ error: "edge_key_invalid" });
    }
  });

  it("reads metadata", async () => {
    const key = objectKey();
    const stored = await env.BUDDY_ARTIFACTS.put(key, "hello", { httpMetadata: { contentType: "text/plain" } });
    const found = await SELF.fetch(metaUrl(key), { headers: internalHeaders() });
    expect(found.status).toBe(200);
    expect(await found.json()).toEqual({ size: 5, content_type: "text/plain", etag: stored.etag });

    const missing = await SELF.fetch(metaUrl(objectKey("missing.txt")), { headers: internalHeaders() });
    expect(missing.status).toBe(404);
    expect(await missing.json()).toEqual({ error: "not_found" });

    for (const url of [metaUrl("captures/x.png"), `${base}/meta`, metaUrl("")]) {
      const invalid = await SELF.fetch(url, { headers: internalHeaders() });
      expect(invalid.status).toBe(400);
      expect(await invalid.json()).toEqual({ error: "key_invalid" });
    }
  });

  it("streams content with the stored content type", async () => {
    const key = objectKey("data.json");
    await env.BUDDY_ARTIFACTS.put(key, '{"a":1}', { httpMetadata: { contentType: "application/json" } });
    const found = await SELF.fetch(`${base}/content?key=${encodeURIComponent(key)}`, { headers: internalHeaders() });
    expect(found.status).toBe(200);
    expect(found.headers.get("Content-Type")).toBe("application/json");
    expect(found.headers.get("Content-Length")).toBe("7");
    expect(found.headers.get("Cache-Control")).toBe("no-store");
    expect(await found.text()).toBe('{"a":1}');

    const missing = await SELF.fetch(`${base}/content?key=${encodeURIComponent(objectKey("missing.txt"))}`, { headers: internalHeaders() });
    expect(missing.status).toBe(404);
    const invalid = await SELF.fetch(`${base}/content?key=other/x`, { headers: internalHeaders() });
    expect(invalid.status).toBe(400);
  });

  it("copies an object with its metadata", async () => {
    const from = objectKey("source.bin");
    const to = objectKey("archive/source.bin");
    await env.BUDDY_ARTIFACTS.put(from, "payload", { httpMetadata: { contentType: "application/octet-stream" }, customMetadata: { upload_id: "source.bin" } });
    const copied = await SELF.fetch(`${base}/copy`, { method: "POST", headers: internalHeaders({ "Content-Type": "application/json" }), body: JSON.stringify({ from, to }) });
    expect(copied.status).toBe(200);
    expect(await copied.json()).toEqual({ size: 7 });
    const target = await env.BUDDY_ARTIFACTS.get(to);
    expect(await target!.text()).toBe("payload");
    expect(target!.httpMetadata?.contentType).toBe("application/octet-stream");
    expect(target!.customMetadata).toEqual({ upload_id: "source.bin" });
    expect((await env.BUDDY_ARTIFACTS.head(from))?.size).toBe(7);

    const missing = await SELF.fetch(`${base}/copy`, { method: "POST", headers: internalHeaders(), body: JSON.stringify({ from: objectKey("missing.bin"), to }) });
    expect(missing.status).toBe(404);
    expect(await missing.json()).toEqual({ error: "not_found" });

    for (const body of ["not json", JSON.stringify({ from, to: "elsewhere/x" }), JSON.stringify({ from: "elsewhere/x", to }), JSON.stringify({ from, to: from }), JSON.stringify({ from })]) {
      const invalid = await SELF.fetch(`${base}/copy`, { method: "POST", headers: internalHeaders(), body });
      expect(invalid.status).toBe(400);
      expect(await invalid.json()).toEqual({ error: "key_invalid" });
    }
  });

  it("deletes idempotently", async () => {
    const key = objectKey("gone.txt");
    await env.BUDDY_ARTIFACTS.put(key, "bye");
    const first = await SELF.fetch(`${base}?key=${encodeURIComponent(key)}`, { method: "DELETE", headers: internalHeaders() });
    expect(first.status).toBe(204);
    expect(await first.text()).toBe("");
    expect(await env.BUDDY_ARTIFACTS.head(key)).toBeNull();
    const second = await SELF.fetch(`${base}?key=${encodeURIComponent(key)}`, { method: "DELETE", headers: internalHeaders() });
    expect(second.status).toBe(204);

    const invalid = await SELF.fetch(`${base}?key=captures/x.png`, { method: "DELETE", headers: internalHeaders() });
    expect(invalid.status).toBe(400);
  });

  it("rejects other methods", async () => {
    const key = objectKey();
    expect((await SELF.fetch(`${base}?key=${encodeURIComponent(key)}`, { headers: internalHeaders() })).status).toBe(405);
    expect((await SELF.fetch(metaUrl(key), { method: "POST", headers: internalHeaders() })).status).toBe(405);
    expect((await SELF.fetch(`${base}/copy`, { headers: internalHeaders() })).status).toBe(405);
    expect((await SELF.fetch(`${base}/unknown`, { headers: internalHeaders() })).status).toBe(404);
  });
});
