# Evidence manifest: Cloudflare plan implementation (branch ikaro/cloudflare-p0-p8)

Redacted record of what was verified, by which tier, on 2026-09-15. Secrets,
tokens, signed URLs and raw provider bodies are excluded by construction.

## Identities (unchanged by this work)

| Item | Value |
| --- | --- |
| Baseline application source | `aa2c1920c57f92a8e93576b46795eaa85cacc03f` (docs commit `0be2c79` on top) |
| API revision / image | `ca-buddy-api-credit--sol-aa2c192` / `buddy:aa2c192-octane` |
| Worker revision / image | `ca-buddy-worker-credit--sol-aa2c192` / `buddy:aa2c192` |
| Public health during work | `{"status":"ok"}` on every probe |
| Container apps after work | ca-memory-hub-credit, ca-redis-credit, ca-buddy-api-credit, ca-buddy-worker-credit, all Running, minReplicas 1 |
| New Key Vault secrets | `buddy-edge-service-key`, `buddy-edge-delegation-secret`, `buddy-scaling-metrics-key` (random, unused until the release) |
| Deleted after evidence capture | probe apps `ca-keda-probe-credit`, `ca-keda-probe2-credit` |

## Release performed on 2026-09-15 (user directive: push to main and deploy)

| Item | Value |
| --- | --- |
| Source | `main` at `ed62db4`; image tag `e29d9f0` (ACR run `cg1p`, both targets) |
| Migrations | `caj-buddy-migrate-credit-38qabuv` Succeeded: `2026_09_16_100000`, `2026_09_16_110000`, `2026_09_16_130000` |
| API | revision `ca-buddy-api-credit--edge-e29d9f0`, Healthy, 100% traffic, 12 secrets (3 new Key Vault references), 57 env entries, all `BUDDY_EDGE_*=false` |
| Worker | revision `ca-buddy-worker-credit--edge-e29d9f0`, Healthy, 1 replica, min 1 / max 4, single rule `queue-depth-api` (`metrics-api`), 11 secrets |
| Jobs | outbox and feedback-health on `e29d9f0`; `caj-buddy-artifacts-credit` created (cron `15 3 * * *`) |
| Probes after cutover | `/api/health` ok; `/api/ready` ready (db, queue); queue-depth 200 with key, 401 without; capabilities flags all false |
| Rollback position | previous images `aa2c192` / `aa2c192-octane` remain in ACR; worker pre-change export retained outside the repository |

No model probe was run. No Cloudflare resource was created (declined during the
session).

## G1 scale-out experiment (2026-09-15, no inference)

| Time (UTC) | Observation |
| --- | --- |
| 16:02:59 | `pending 0, reserved 0`; worker revision `edge-e29d9f0`, 1 replica |
| 16:05:29 | execution `caj-buddy-outbox-credit-0qdidjc` dispatched 30 x 90 s synthetic jobs (batch `syn-28wqxack`); `pending 30` on `laravel-database-queues:default` |
| 16:06:29 | 3 replicas provisioned (1 Running, 2 starting), `pending 27, reserved 3` |
| 16:07:02 | 3 Running, `pending 27, reserved 3` |
| 16:08:09 | 3 Running, `pending 24, reserved 3` (drain about 2 jobs/min) |
| 16:15:38 | `pending 11` -> 2 Running (scale-in step, `ceil(11/10)`) |
| 16:23:08 | `pending 2` -> 1 Running |
| 16:24:41 | `pending 0`; last synthetic job finishing; 1 replica remains through 16:34 |

Desired replicas follow `ceil(pending / 10)`, so a 30-job backlog yields three
workers, which satisfies "at least two ready workers" with the configured
threshold, and scale-in stepped down with the backlog while one replica
remained. Log Analytics since the release shows exactly one `KEDAScalerFailed`
(16:01:10, the old revision's Redis rule before its scaled object was removed)
and `Scaler metrics-api is built` at 16:01:24 with no failure afterwards.
Worker console logs contain 30 "Synthetic queue load job finished" lines with
30 distinct indexes and no index started twice, so no job ran more than once.
`buddy_runs` is untouched by design (the job performs no database write).
Gate G1 passes.

## Gate status

| Gate | Status | Evidence |
| --- | --- | --- |
| G0 baseline | passed | clean tree at 0be2c79; 290 tests + 3 skipped locally before changes |
| G1 P0 | passed: key fix and metrics-api signal live on worker `edge-e29d9f0`; 30-job backlog scaled 1 -> 3 -> 1 with no duplicate execution | ADR 0012, runbook, Log Analytics counts, in-container measurement, probe results, experiment timeline below |
| G2 contracts | passed locally | OutboxTopicRegistryTest (unknown topics quarantined), EdgeIdentityTest (cross-client isolation, revocation fails closed), RedisQueueScaleRuleTest |
| G3 transport | passed locally (fakes) | Cloudflare failure leaves deliveries pending with backoff while local dispatch completes; replay command; DLQ handled Worker-side |
| G4 dashboard | Worker tests | see cloudflare/buddy-edge test results in the handoff |
| G5 recovery | passed locally; PostgreSQL race suites in CI | EdgeSupervisionTest, PostgresEdgeConcurrencyTest, existing PostgresInterventionTest |
| G6 artifacts and cache | passed locally (fake object store) | ArtifactStorageTest, ArtifactProcessingTest; origin reduction measurement pending preview |
| G7 browser | open (external) | Browser Run billing coverage unconfirmed; live flag false; mock tests only |
| G8 release | Azure rollout performed with flags off; Cloudflare preview not provisioned | this manifest, rollout recipe, rollback lifecycle, deployed identities above |

## Tier-2 measurements (P0)

- Inside worker replica `ca-buddy-worker-credit--sol-aa2c192-…spjmt`: `prefix=laravel-database-`, `queue=default`, `db=0`, `llen=0`, `delayed=0`, `reserved=0`, `dbsize=0`; `ca-redis-credit` resolves to 100.100.226.25 and connects on 6379; the internal FQDN resolves to 100.100.0.209 and does not accept 6379.
- Log Analytics (`ContainerAppSystemLogs_CL`, 14 days): 1,469 `KEDAScalerFailed` on the worker across revisions 0000017 … sol-aa2c192, all `dial tcp 100.100.226.25:6379: connect: connection refused`.
- Probe app with the FQDN address: 15 `KEDAScalerFailed`, `dial tcp 100.100.0.209:6379: i/o timeout`.
- Probe app with a `metrics-api` rule against api.github.com: `api returned 403` (HTTP reached), scaler rebuilt each poll.
- `Replicas` metric, hourly maximum over 7 days: 1.0 in all 168 samples.

## Tests

| Suite | Result |
| --- | --- |
| PHPUnit (SQLite, local, `ed62db4`) | 412 passed, 6 skipped (PostgreSQL-only), 1953 assertions |
| PHPUnit PostgreSQL filter (CI run 34989976639 on `17fa5aa`; local throwaway postgres:16 during P4) | success; 79 passed (479 assertions) locally incl. `PostgresEdgeConcurrencyTest` |
| Worker (`cloudflare/buddy-edge`) | vitest 81 passed (7 files); `tsc --noEmit` 0 errors; `wrangler deploy --dry-run --env preview` ok |
| Pint | passes on every touched file |
