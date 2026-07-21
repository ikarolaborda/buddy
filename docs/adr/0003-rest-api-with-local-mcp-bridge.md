# ADR 0003: API-key REST first; MCP stays local via a thin stdio bridge

**Status:** Accepted — 2026-07-21

## Decision

Buddy exposes an API-key-authenticated REST API (`bdy_live_<public>_<secret>`, HMAC-SHA-256
digest with a peppered comparison, scopes, revocation). MCP remains a local stdio transport:
`bin/buddy-mcp-bridge` receives only `BUDDY_BASE_URL` and `BUDDY_API_KEY` and forwards tool
calls over HTTPS. Native remote Streamable HTTP MCP is deferred.

## Rationale

An API key alone is not spec-compliant MCP authorization. Remote MCP requires OAuth 2.1
resource-server behavior, audience/resource binding, no token passthrough, Origin
validation, and cross-client isolation tests (MCP spec 2025-11-25). Shipping REST + a thin
bridge delivers remote capability now without violating the MCP authorization model.

## Consequences

- The bridge holds no database or provider credentials; the previous full-`.env` Docker
  mount is removed after cutover.
- Both the bridge and `buddy:mcp-server` negotiate protocol versions instead of hard-coding
  `2024-11-05`.
- Phase 2 (native HTTP MCP) requires the OAuth 2.1 checklist in plan §10.2 before exposure.
