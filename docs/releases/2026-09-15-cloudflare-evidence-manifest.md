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

No application image was built or deployed. No live revision was rolled. No
model probe was run. No Cloudflare resource was created.

## Gate status

| Gate | Status | Evidence |
| --- | --- | --- |
| G0 baseline | passed | clean tree at 0be2c79; 290 tests + 3 skipped locally before changes |
| G1 P0 | key fix and signal design done; scale-out proof pending release | ADR 0012, runbook, Log Analytics counts, in-container measurement, probe results |
| G2 contracts | passed locally | OutboxTopicRegistryTest (unknown topics quarantined), EdgeIdentityTest (cross-client isolation, revocation fails closed), RedisQueueScaleRuleTest |
| G3 transport | passed locally (fakes) | Cloudflare failure leaves deliveries pending with backoff while local dispatch completes; replay command; DLQ handled Worker-side |
| G4 dashboard | Worker tests | see cloudflare/buddy-edge test results in the handoff |
| G5 recovery | passed locally; PostgreSQL race suites in CI | EdgeSupervisionTest, PostgresEdgeConcurrencyTest, existing PostgresInterventionTest |
| G6 artifacts and cache | passed locally (fake object store) | ArtifactStorageTest, ArtifactProcessingTest; origin reduction measurement pending preview |
| G7 browser | open (external) | Browser Run billing coverage unconfirmed; live flag false; mock tests only |
| G8 release | partially | this manifest, rollout recipe, rollback lifecycle; production rollout not performed |

## Tier-2 measurements (P0)

- Inside worker replica `ca-buddy-worker-credit--sol-aa2c192-…spjmt`: `prefix=laravel-database-`, `queue=default`, `db=0`, `llen=0`, `delayed=0`, `reserved=0`, `dbsize=0`; `ca-redis-credit` resolves to 100.100.226.25 and connects on 6379; the internal FQDN resolves to 100.100.0.209 and does not accept 6379.
- Log Analytics (`ContainerAppSystemLogs_CL`, 14 days): 1,469 `KEDAScalerFailed` on the worker across revisions 0000017 … sol-aa2c192, all `dial tcp 100.100.226.25:6379: connect: connection refused`.
- Probe app with the FQDN address: 15 `KEDAScalerFailed`, `dial tcp 100.100.0.209:6379: i/o timeout`.
- Probe app with a `metrics-api` rule against api.github.com: `api returned 403` (HTTP reached), scaler rebuilt each poll.
- `Replicas` metric, hourly maximum over 7 days: 1.0 in all 168 samples.

## Tests

Recorded in the handoff after each package; the final totals are in the
pull request description.
