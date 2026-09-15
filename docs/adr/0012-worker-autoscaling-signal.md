# ADR 0012: Worker autoscaling signal

**Status:** Accepted — 2026-09-15 (P0 of the Cloudflare plan)

## Context

The worker container app `ca-buddy-worker-credit` scales 1–4 replicas on a
KEDA `redis` rule. Two independent defects meant it has never scaled:

1. **Wrong key.** The rule measured `buddy:queue:default`. Laravel writes the
   pending list at `config('database.redis.options.prefix') . 'queues:' . <queue>`,
   and the worker sets neither `APP_NAME`, `REDIS_PREFIX` nor `REDIS_QUEUE`, so
   the real key is `laravel-database-queues:default`. Measured inside the
   production worker on 2026-09-15 (`prefix=laravel-database-`, `queue=default`,
   `db=0`). A rule that names a key that never exists reads 0 forever.
2. **Unreachable target.** Log Analytics holds 1,469 `KEDAScalerFailed` events
   for the worker between 2026-09-01 and 2026-09-15, on every revision, every
   polling interval: `dial tcp 100.100.226.25:6379: connect: connection refused`.
   From inside a worker replica the short name `ca-redis-credit` resolves to the
   same 100.100.226.25 and connects, so the failure is specific to the network
   namespace the platform scaler runs in. The internal FQDN
   `ca-redis-credit.internal.<env>.azurecontainerapps.io` resolves to
   100.100.0.209 and refuses or times out on 6379 from both the worker and a
   throwaway probe app (`ca-keda-probe-credit`, 15 `KEDAScalerFailed`, `i/o timeout`).
   The environment is not internal-only, Redis has no VNet address, and exposing
   it publicly is excluded by the plan.

A second probe app (`ca-keda-probe2-credit`) with a `metrics-api` rule against a
public HTTPS JSON endpoint (the GitHub REST API) reached it: the scaler logged
`api returned 403`, GitHub's policy answer to an unauthenticated request without
a user agent, and rebuilt the scaler on every poll. An HTTP status from the
target proves the platform scaler has public HTTPS egress; the same scaler
never got past the TCP dial to Redis. Both probe apps were deleted after the
evidence was captured.

## Decision

- Fix the key regardless of transport: `redisQueueListName` in
  `infra/azure/modules/buddy-worker.bicep` defaults to the measured key, and
  `tests/Unit/RedisQueueScaleRuleTest.php` pins the derivation so a change to
  `APP_NAME`, `REDIS_PREFIX` or `REDIS_QUEUE` on the worker fails CI.
- Move the worker's scale signal to a `metrics-api` rule that polls the Buddy
  API's authenticated endpoint `GET /api/internal/scaling/queue-depth`
  (`X-Buddy-Scaling-Key`, Key Vault secret `buddy-scaling-metrics-key`). The
  endpoint reports the same list (`pending`, plus `delayed` and `reserved` for
  operators), so `listLength: 10` keeps its meaning as `targetValue: 10`. The
  rule type is selected by the `workerScaleRuleType` parameter; `redis` remains
  available for environments where the scaler can reach Redis.
- Keep API 1–5 and worker 1–4 replicas; keep at least one replica through the
  startup-credit milestone window (approximately 2026-10-10).

## Consequences

- The scaler no longer needs Redis network access or the Redis password. The
  API already holds a Redis connection and answers in one `LLEN`.
- Scaling latency adds one HTTPS round trip per poll (30 s interval); the
  endpoint is `no-store` and unauthenticated requests are refused.
- Switching the live rule requires the image that serves the endpoint, so the
  live change belongs to the release that deploys this code (plan §15 step 2),
  not to a configuration-only revision. Until then the worker keeps one replica,
  which is the behavior it has had since deployment.
- The scale-out proof (synthetic backlog via `buddy:queue:synthetic --confirm`,
  at least two ready replicas, drain within cooldown, no duplicate results)
  remains the G1 gate and is recorded in the runbook when it runs.

## Rejected alternatives

- **Short-name or FQDN Redis address for KEDA.** Measured unreachable from the
  scaler (refused and timeout respectively). The July short-name change fixed
  the worker path only.
- **Public TCP ingress for Redis.** Excluded by the plan; it would expose a
  password-only datastore to the internet.
- **Azure Managed Redis with a private endpoint.** Quota was zero in both
  candidate regions when last requested, it changes the credit-covered estate
  during the milestone window, and it is not needed for the scaler once the
  signal moves to the API. It stays a valid later option for Redis persistence.
- **PostgreSQL scaler.** Would measure tasks rather than the queue the worker
  consumes, and the scaler's reachability of the PostgreSQL private endpoint
  was not verified.
