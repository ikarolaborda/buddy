# ADR 0006: Native remote MCP with static bearer keys for first-party agents

**Status:** Accepted — 2026-07-22. Amends ADR 0003.

## Context

ADR 0003 deferred native Streamable HTTP MCP behind the full OAuth 2.1 checklist. The
`/api/mcp` endpoint then shipped (commit 2d2b1ec) with static `bdy_live_*` bearer keys
because the only consumers are first-party coding agents operated by the repository
owner: ~20 local Claude Code projects plus onboarded remote machines. The shipped state
contradicted the accepted ADR; this record resolves the contradiction deliberately
instead of leaving it as drift.

## Decision

Static bearer API keys are an accepted authorization mechanism for `/api/mcp` **for
first-party agents only**. Compensating controls, all verified in code:

- TLS-only Azure ingress; keys never travel in URLs.
- Per-key scopes and per-client task ownership fences (`RemoteMcpHandler`).
- Origin validation middleware (`mcp.origin`) rejects any browser-originated
  cross-site request not allowlisted in `buddy.api.allowed_origins`; requests without
  an Origin header (all first-party agent transports) pass. Browser-based clients such
  as MCP Inspector (`http://localhost:6274`) require explicit allowlisting, localhost
  included.
- CORS is closed by `config/cors.php` (empty allowlist fed by the same
  `BUDDY_MCP_ALLOWED_ORIGINS` variable). Note: before this ADR the framework default
  (`allowed_origins: ['*']`) applied; Origin validation and CORS lockdown shipped
  together. Preflight OPTIONS requests are answered by `HandleCors` before route
  middleware; the load-bearing controls remain bearer auth plus TLS.
- No token passthrough: Buddy keys are never forwarded to providers or the memory hub.

OAuth 2.1 resource-server behavior, audience binding, and third-party client
registration remain **required before any third-party or public exposure**. That part
of ADR 0003 stands.

## Consequences

- The stdio bridge (`bin/buddy-mcp-bridge`) remains a supported fallback transport.
- Adding a browser-based MCP client is a config change (`BUDDY_MCP_ALLOWED_ORIGINS`),
  not a code change, and widens CORS for exactly the listed origins.
- Known follow-up: the shared `throttle:120,1` limit keys by IP, so many agents behind
  one egress IP share one bucket; re-key by api_client if polling volume grows.
