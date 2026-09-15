import { SCOPE_COOKIE, SESSION_COOKIE } from "./env";

export const CONTENT_SECURITY_POLICY =
  "default-src 'self'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'";

/** Adds the mandatory security headers. API responses are additionally marked `no-store`. */
export function applySecurityHeaders(response: Response, options: { api: boolean }): Response {
  // A 101 response carries the WebSocket; its headers are not observable by the page and it cannot be rebuilt.
  if (response.status === 101) return response;
  const headers = new Headers(response.headers);
  headers.set("Content-Security-Policy", CONTENT_SECURITY_POLICY);
  headers.set("Referrer-Policy", "no-referrer");
  headers.set("X-Content-Type-Options", "nosniff");
  if (options.api) headers.set("Cache-Control", "no-store");
  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

export function json(data: unknown, status = 200, headers?: HeadersInit): Response {
  const h = new Headers(headers);
  h.set("Content-Type", "application/json; charset=utf-8");
  return new Response(JSON.stringify(data), { status, headers: h });
}

export function errorJson(status: number, error: string, extra?: Record<string, unknown>): Response {
  return json({ error, ...extra }, status);
}

export function parseCookies(header: string | null): Map<string, string> {
  const cookies = new Map<string, string>();
  if (!header) return cookies;
  for (const part of header.split(";")) {
    const index = part.indexOf("=");
    if (index === -1) continue;
    const name = part.slice(0, index).trim();
    const value = part.slice(index + 1).trim();
    if (name) cookies.set(name, value);
  }
  return cookies;
}

function cookieAttributes(maxAgeSeconds: number): string {
  return `HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=${maxAgeSeconds}`;
}

export function sessionCookies(sessionToken: string, scope: { client_id: string; task_id: string }, maxAgeSeconds: number): string[] {
  const age = Math.max(1, Math.floor(maxAgeSeconds));
  return [
    `${SESSION_COOKIE}=${sessionToken}; ${cookieAttributes(age)}`,
    `${SCOPE_COOKIE}=${encodeScope(scope)}; ${cookieAttributes(age)}`,
  ];
}

export function clearSessionCookies(): string[] {
  return [`${SESSION_COOKIE}=; ${cookieAttributes(0)}`, `${SCOPE_COOKIE}=; ${cookieAttributes(0)}`];
}

export function encodeScope(scope: { client_id: string; task_id: string }): string {
  return base64UrlEncode(new TextEncoder().encode(JSON.stringify({ c: scope.client_id, t: scope.task_id })));
}

export function decodeScope(value: string | undefined): { client_id: string; task_id: string } | null {
  if (!value) return null;
  try {
    const parsed = JSON.parse(new TextDecoder().decode(base64UrlDecode(value))) as { c?: unknown; t?: unknown };
    if (typeof parsed.c !== "string" || typeof parsed.t !== "string" || !parsed.c || !parsed.t) return null;
    return { client_id: parsed.c, task_id: parsed.t };
  } catch {
    return null;
  }
}

export function base64UrlEncode(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

export function base64UrlDecode(value: string): Uint8Array {
  const padded = value.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (value.length % 4)) % 4);
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
}

export async function sha256Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, "0")).join("");
}

/** Constant-time comparison of two secrets. */
export function secretsEqual(a: string | undefined | null, b: string | undefined | null): boolean {
  if (!a || !b) return false;
  const encoder = new TextEncoder();
  const bytesA = encoder.encode(a);
  const bytesB = encoder.encode(b);
  if (bytesA.byteLength !== bytesB.byteLength) return false;
  return crypto.subtle.timingSafeEqual(bytesA, bytesB);
}

export async function readJsonBody<T>(request: Request, maxBytes = 64 * 1024): Promise<T | null> {
  const text = await request.text();
  if (text.length > maxBytes) return null;
  try {
    const parsed: unknown = JSON.parse(text);
    if (parsed === null || typeof parsed !== "object") return null;
    return parsed as T;
  } catch {
    return null;
  }
}
