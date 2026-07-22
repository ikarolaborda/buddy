# ADR 0008: Octane/FrankenPHP runtime, API-key cache, and model routing

**Status:** Accepted — 2026-07-22

## Decision

Three performance levers ship together (commits 4ef0853 + c6ad9c0; method and
full benchmark data in `docs/plans/2026-07-22-performance-levers.md`):

1. **API-key lookup cache.** Verified key+client attributes cached for a
   short TTL (`BUDDY_API_KEY_CACHE_TTL`, default 60s, 0 disables) as plain
   arrays, rehydrated via `newFromBuilder`. Models are never cached: Laravel
   13 cache stores unserialize with an `allowed_classes` restriction, and a
   cached Eloquent model 500s on every hit (caught by the zero-traffic canary,
   fixed in c6ad9c0, regression-tested against a serializing store).
   Accepted risks: the peppered secret digest resides in Redis (private VNet,
   TLS); direct-DB revocation or client deactivation lingers at most the TTL;
   `revoke()` invalidates immediately; expiry never lingers.
2. **Problem-type model routing.** `configuration`/`other` evaluations route
   to `gpt-5.4-mini` (config-driven, `BUDDY_MODEL_ROUTING` kill switch).
   Evaluator agent only; an active `agent_profiles` row wins verbatim and
   suppresses routing. Verified in production from run provenance: routed runs
   record and call the fast model, full-tier runs keep `gpt-5.4`.
3. **Octane + FrankenPHP** for the API container (`Dockerfile.octane`,
   `dunglas/frankenphp:1-php8.5`, 6 workers, `max-requests=250`,
   `max_execution_time=150`). Workers are sized for blocking I/O; the enabling
   prerequisite is that REST evaluate became async by default (`?sync=1`
   opt-in), so inline provider calls cannot starve the pool. `trustProxies`
   configured for Container Apps ingress. The fpm image remains the worker
   image and the rollback revision.

## Measured results

| Path (live, reused connection) | Before | After |
|---|---|---|
| Authed MCP `tools/list` p50 | ~135-145ms (~90ms server) | **65ms (~17ms server)** |
| Authed p95 | ~145ms | **70-72ms** |
| `/api/health` p50 | ~55ms | **51ms** (network floor is ~48ms) |
| Replica memory (6 workers) | ~190MB fpm | ~190MB, flat, 0 restarts |

Local A/B (same env, statuses asserted): authed p50 3.3ms → 1.7ms.

Not confirmed: an end-to-end latency win from mini-routing. One timed pair ran
14s (mini) vs 14s (full); queue pickup, memory search, and structured output
dominate. Routing stands on cost and provenance grounds; revisit the fast list
if latency data accumulates differently.

## Rollout and rollback

Multiple-revision mode with a zero-traffic canary probed on its per-revision
FQDN before any traffic shift (this caught the cache serialization defect that
136 local tests could not, because the test suite's array store never
serializes). Rollback: `az containerapp ingress traffic set` back to the
provisioned fpm revision.

## Follow-ups

- Composer reports 22 advisories across 9 packages (pre-existing); audit them.
- Re-key `throttle:120,1` by api_client (correct now that trustProxies gives
  real client IPs, but all agents behind one egress IP still share a bucket).
- Deactivate the fpm API revision once the Octane bake period passes.
