export interface CapturePolicy {
  allowed_hosts: string[];
  allow_subresources_same_host: boolean;
  redirects_allowed: boolean;
}

export type PolicyCheck = { ok: true; url: URL; host: string } | { ok: false; reason: string };

const METADATA_HOSTS = new Set(["169.254.169.254", "metadata.google.internal", "100.100.100.200", "metadata", "metadata.internal"]);
const DENIED_SUFFIXES = [".internal", ".local", ".localhost", ".arpa", ".home", ".lan", ".intranet", ".corp"];

const IPV4_LITERAL = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
const CONTROL_OR_SPACE = /[\s\x00-\x1f\x7f]/;

export function isIpv4Literal(host: string): boolean {
  return IPV4_LITERAL.test(host);
}

export function isIpv6Literal(host: string): boolean {
  return host.startsWith("[") || host.includes(":");
}

export function isPrivateIpv4(host: string): boolean {
  const match = IPV4_LITERAL.exec(host);
  if (!match) return false;
  const [a, b] = [Number(match[1]), Number(match[2])];
  if (a === 10 || a === 127 || a === 0) return true;
  if (a === 172 && b >= 16 && b <= 31) return true;
  if (a === 192 && b === 168) return true;
  if (a === 169 && b === 254) return true;
  if (a === 100 && b >= 64 && b <= 127) return true;
  return false;
}

export function normalizeHost(host: string): string {
  return host.trim().toLowerCase().replace(/\.$/, "");
}

/**
 * Enforced BEFORE any navigation. Every rule fails closed: http(s) only, no userinfo, no IP literals, no punycode,
 * exact allowlist match, and a hard deny for localhost, internal, link-local and cloud metadata destinations.
 */
export function evaluateCapturePolicy(rawUrl: string, policy: CapturePolicy): PolicyCheck {
  if (typeof rawUrl !== "string" || rawUrl.length === 0 || rawUrl.length > 2048) return { ok: false, reason: "url_invalid" };
  if (CONTROL_OR_SPACE.test(rawUrl)) return { ok: false, reason: "url_invalid" };
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    return { ok: false, reason: "url_invalid" };
  }
  if (url.protocol !== "http:" && url.protocol !== "https:") return { ok: false, reason: "scheme_not_allowed" };
  if (url.username !== "" || url.password !== "") return { ok: false, reason: "userinfo_not_allowed" };

  const host = normalizeHost(url.hostname);
  if (host.length === 0) return { ok: false, reason: "host_missing" };
  if (isIpv6Literal(host) || isIpv4Literal(host)) return { ok: false, reason: "ip_literal_not_allowed" };
  if (host.split(".").some((label) => label.startsWith("xn--"))) return { ok: false, reason: "punycode_not_allowed" };
  if (/[^a-z0-9.-]/.test(host)) return { ok: false, reason: "host_invalid" };
  if (host === "localhost" || host.endsWith(".localhost")) return { ok: false, reason: "localhost_denied" };
  if (METADATA_HOSTS.has(host)) return { ok: false, reason: "metadata_host_denied" };
  if (DENIED_SUFFIXES.some((suffix) => host.endsWith(suffix))) return { ok: false, reason: "private_host_denied" };
  if (!host.includes(".")) return { ok: false, reason: "private_host_denied" };

  const allowed = new Set((policy.allowed_hosts ?? []).map(normalizeHost).filter((h) => h.length > 0));
  if (allowed.size === 0 || allowed.size > 50) return { ok: false, reason: "allowlist_invalid" };
  for (const entry of allowed) {
    if (entry.includes("*") || isIpv4Literal(entry) || isIpv6Literal(entry) || entry.split(".").some((l) => l.startsWith("xn--"))) {
      return { ok: false, reason: "allowlist_invalid" };
    }
  }
  if (!allowed.has(host)) return { ok: false, reason: "host_not_allowed" };
  return { ok: true, url, host };
}

/** Whether a request made by the page may proceed under the policy (used by request interception). */
export function requestAllowed(requestUrl: string, allowedHost: string, policy: CapturePolicy, options: { isDocument: boolean; isRedirect: boolean }): boolean {
  let url: URL;
  try {
    url = new URL(requestUrl);
  } catch {
    return false;
  }
  if (url.protocol !== "http:" && url.protocol !== "https:") return false;
  if (url.username !== "" || url.password !== "") return false;
  const host = normalizeHost(url.hostname);
  if (host !== allowedHost) return false;
  if (options.isRedirect && !policy.redirects_allowed) return false;
  if (!options.isDocument && !policy.allow_subresources_same_host) return false;
  return true;
}

/** Strips query strings, fragments and userinfo so evidence never carries tokens or cookies. */
export function redactUrl(value: string): string {
  try {
    const url = new URL(value);
    url.search = "";
    url.hash = "";
    url.username = "";
    url.password = "";
    return url.toString();
  } catch {
    return value.split("?")[0]!.split("#")[0]!.slice(0, 200);
  }
}

const SENSITIVE_HEADER = /\b(authorization|proxy-authorization|cookie|set-cookie|api[-_]?key|access[-_]?token|refresh[-_]?token|token|secret|password)\b\s*[:=]?\s*(?:bearer\s+)?[^\s;,]*/gi;
const BEARER_VALUE = /\bbearer\s+[^\s;,]*/gi;

/** Removes credential material (header names with their values, bearer tokens, cookie pairs) and bounds the length. */
export function redactText(value: string, max = 500): string {
  return value.replace(SENSITIVE_HEADER, "[redacted]").replace(BEARER_VALUE, "[redacted]").slice(0, max);
}
