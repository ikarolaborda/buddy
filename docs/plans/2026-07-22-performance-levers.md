# Performance Levers: Key Cache, Model Routing, Octane/FrankenPHP

**Status:** Implemented (commit 4ef0853); live verification appended below
**Date:** 2026-07-22

## Baseline (measured live, reused connection)

| Path | Latency |
|---|---|
| `/api/health` | ~55ms total, ~48ms network RTT, ~7ms server |
| Authed MCP `tools/list` | ~135-145ms total, ~85-95ms server |
| Async evaluation end to end | 11-16s, ~85% GPT-5.4 |

## Lever 1 — API-key lookup cache

`ApiKeyService::verify()` ran two SELECTs plus a `last_used_at` UPDATE per
request. Now: verified key+client cached for `BUDDY_API_KEY_CACHE_TTL`
(default 60s, 0 disables); `revoke()` invalidates immediately; expiry is
re-checked per request so it never lingers; direct-DB revocation or client
deactivation lingers at most the TTL (accepted); unknown public ids are
never cached (the 401 path always hits the database); `last_used_at`
throttles to one write per TTL window. The peppered secret digest now also
resides in the cache store (Redis in Azure, private VNet + TLS) — accepted
in ADR 0008.

## Lever 2 — Problem-type model routing

`AgentProfileResolver::resolve()` takes an optional `ProblemType`. For the
evaluator-optimizer agent only, problem types listed in
`buddy_agents.routing.fast_problem_types` (default `configuration,other`)
route to `routing.fast_model` (default `gpt-5.4-mini`, verified present on
the live OpenAI model list 2026-07-22). An active `agent_profiles` row
suppresses routing entirely (ops escape hatch). `BUDDY_MODEL_ROUTING=false`
is the kill switch. The refiner agent is never routed, and
`recordRunConfiguration` resolves with the problem type so `model_used`
always records the model actually called.

## Lever 3 — Octane + FrankenPHP

Prerequisites shipped first (both required by review):

- REST evaluate is async by default (`?sync=1` opt-in; automatic inline
  fallback when the queue driver is `sync`). Inline provider calls block a
  server worker for up to 120s, which would starve a fixed worker pool.
- `trustProxies('*')`: behind Container Apps ingress, `Request::ip()` was
  the Envoy pod IP, collapsing every IP-keyed throttle into one bucket.

Runtime: `docker/production/Dockerfile.octane`, `dunglas/frankenphp:1-php8.5`,
extensions via `install-php-extensions` (pdo_pgsql, redis, pcntl, opcache),
same php.ini and stale-cache purge as the fpm image. Entrypoint caches
config+routes at container start, then
`octane:frankenphp --host=0.0.0.0 --port=8080 --workers=6 --max-requests=250`.
Workers sized for blocking I/O on 0.5 vCPU / 1Gi; `max-requests` recycles
workers as a leak guard; `octane.max_execution_time=150` preserves the fpm
`fastcgi_read_timeout` parity. The fpm image remains the worker-container
image and the rollback path.

### Local A/B benchmark (method + results)

Same commit, both production images, identical env: sqlite in-container
(no bind mounts), database cache/queue, auth on, key cache OFF
(`BUDDY_API_KEY_CACHE_TTL=0`) to isolate the runtime delta, config cached
in BOTH images, 260 keep-alive requests per path with the first 50
discarded as warmup, response codes asserted 200 with the full 6-tool
payload. Apple M-series host; absolute numbers are not Azure-representative
(no Postgres/Redis TLS connect cost locally, which is Octane's largest
production win), the ratio is the signal.

| Path | fpm p50 / p95 | octane p50 / p95 | delta p50 |
|---|---|---|---|
| `/api/health` | 1.1ms / 1.4ms | 0.5ms / 0.7ms | -55% |
| Authed `tools/list` | 3.3ms / 4.7ms | 1.7ms / 2.1ms | -48% |

Adoption gate (>30% server-side p50 improvement): passed.

### Deployment and rollback

`az acr build` two images from a clean worktree at the reviewed SHA:
`buddy:<sha>` (fpm; worker + rollback) and `buddy:<sha>-octane` (API).
API app switched to multiple-revision mode; the octane revision is probed
on its per-revision FQDN with zero traffic before receiving 100%. The fpm
revision stays provisioned for instant rollback
(`az containerapp ingress traffic set`).

## Live post-deployment verification (2026-07-22, revision octane2)

- Zero-traffic canary (revision octane1) caught a real defect: cached Eloquent
  models 500 on every cache hit because Laravel 13 cache stores unserialize
  with `allowed_classes` (`RedisStore.php:534`). The array store used in tests
  never serializes, so 136 green tests missed it. Fixed in c6ad9c0 (attribute
  arrays + `newFromBuilder`), regression-tested against the file store, which
  reproduced the exact production signature pre-fix.
- Canary octane2 probed at zero traffic: 40/40 authed calls returned 200 with
  the 6-tool payload; unauth 401 and cross-origin 403 preserved.
- Post-cutover main endpoint (statuses asserted): authed `tools/list` p50
  65ms / p95 70ms (baseline 135-145ms; server-side ~90ms to ~17ms); health p50
  51ms against a ~48ms network floor.
- Model routing verified from run provenance inside the container:
  `configuration` evaluations record and call `gpt-5.4-mini` (runs 17, 18),
  `bug`/`performance` keep `gpt-5.4` (runs 16, 19). End-to-end eval time was
  14s on both tiers in a single timed pair, so no latency win is claimed for
  routing; the win is cost and the ops lever.
- Stability: replica memory flat at ~190MB of 1Gi with 6 workers, zero
  restarts over the observation window; `max-requests=250` recycles workers
  and the RestartCount alert (ADR 0007) watches ongoing.
- Rollback path live: fpm revision 0000011 remains provisioned; the worker
  runs the fpm image `buddy:c6ad9c0`-lineage.
