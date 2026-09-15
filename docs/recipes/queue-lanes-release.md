# Queue lanes and Horizon: release and rollback

Companion to [ADR 0014](../adr/0014-queue-lanes-and-in-replica-concurrency.md).
The worker changes command (`php artisan horizon`), size (1 vCPU / 2 GiB),
grace period (600 s) and scale rules (two `metrics-api` rules on
`scaling.evaluations` and `scaling.council`). Jobs change queue names, so the
order of operations matters: the worker must consume the new lanes before any
release routes work onto them.

## Release order

1. Build and push both images for the reviewed SHA (`buddy:<sha>` for worker
   and jobs, `buddy:<sha>-octane` for the API).
2. **Worker first.** Export the live app (`az containerapp show -o json`),
   transform it (image, `command: [php, artisan, horizon]`, resources,
   `terminationGracePeriodSeconds: 600`, `BUDDY_WORKERS_*` env, the two lane
   rules, a fresh `template.revisionSuffix`) and apply it with
   `az containerapp update --yaml`. Never pass the command through `--args`.
   Confirm in the console logs that Horizon started and that four
   supervisors are running (`Horizon started successfully`, then one
   `Processing jobs from the [evaluations] queue` line per process).
3. **Then API and jobs** on the new image. From this revision on, jobs are
   pushed to `evaluations`, `council` and `fast`; the worker's legacy
   supervisor drains whatever the previous API revision left in `default`.
4. Verify with `GET /api/internal/scaling/queue-depth` (per-lane counts and
   the `scaling` block), `php artisan buddy:queue:synthetic --count=12
   --seconds=60 --lane=evaluations --confirm` (expect `lanes.evaluations.reserved`
   to reach 6 on one replica within seconds and a second replica when
   `scaling.evaluations` exceeds 6), and `php artisan buddy:queue:report
   --since=1h` after a few real evaluations submitted together (expect
   `max_concurrent` above 1 and queue waits of a few seconds).

## Rollback

- **Routing only** (worker healthy, lanes misbehaving): set
  `BUDDY_QUEUE_EVALUATIONS=default`, `BUDDY_QUEUE_COUNCIL=default`,
  `BUDDY_QUEUE_FAST=default` on the API and the jobs. Everything flows back
  to the legacy list, which the worker's legacy supervisor still consumes.
  Jobs already on the lanes finish on their lane supervisors.
- **Worker revision** (Horizon itself misbehaving): activate the previous
  worker revision *and* apply the routing rollback above at the same time.
  The old worker only reads `default`; anything still on a lane at that
  moment waits until a lane-aware worker returns or the supervision workflow
  recovers the task through its lease.
- Never scale the worker to zero; the credit milestone requires one replica.

## Contract changes: expand, migrate, contract

The worker's KEDA rules poll the API, so a release that changes both the
rule's `valueLocation` and the worker opens a window in which the new rules
read the old response. On 2026-09-15 that window lasted 75 seconds (six
`KEDAScalerFailed` "valueLocation must point to value of type number" events,
no scale action, minReplicas 1 kept the baseline). Next time, expand the
endpoint first (serve both shapes), then deploy workers that consume every
lane, then switch routing, and remove the old shape only after the rollback
period (Buddy review 01M2K4BJ87D0DEYT2XC94BWJR2).

## Capacity, health job and alerts

- Capacity is `BUDDY_WORKERS_EVALUATIONS` (5) × `maxReplicas` (3) = 15
  concurrent evaluations, the provider cap (ADR 0014 amendment). Changing
  either value means the worker template (`--yaml`), `config/buddy.php` and
  the Bicep defaults together; `tests/Feature/QueueLanesTest` fails on drift.
- `php artisan buddy:queue:health` (job `caj-buddy-queue-health-<env>`, every
  15 minutes) logs `BUDDY_QUEUE_DEGRADED`; the alerts module deploys
  standalone:
  `az deployment group create -g rg-buddy-<env> --template-file infra/azure/modules/alerts.bicep --parameters environment=<env> alertEmailAddress=<operator> monthlyBudgetAmount=<current> budgetStartDate=<existing budget start> logAnalyticsWorkspaceId=<workspace resource id>`
  (run with `--what-if` first; the budget start date must match the existing
  budget or Azure rejects the update).
- Forced-kill rehearsal: `scratchpad/horizon-kill-rehearsal.sh` pattern,
  production image, isolated Redis, `REDIS_QUEUE_RETRY_AFTER=30` only there.

## Operations notes (from the 2026-09-15 reviews)

- **Scaling key.** Rotate `buddy-scaling-metrics-key` every 90 days or on
  suspected exposure, never per revision: add the new key to the API as a
  second accepted value, switch the worker rule secret, confirm polls, then
  retire the old key within 24 hours. The endpoint answers 401 to a bad or
  missing key and 404 when no key is configured, both with `no-store`.
- **Queue health detection envelope.** Threshold 300 s + up to 15 min to the
  next probe + up to 15 min to the next alert evaluation + ingestion and
  notification: expect about 35 minutes from enqueue for a persistent
  breach. The job never retries (`replicaRetryLimit 0`) so a degraded exit
  does not double-log. A probe that cannot start is silent; a
  missing-heartbeat alert is the open follow-up.
- **Cap validity.** 5 × 3 = 15 is steady-state sizing for default effort
  (p50 45–56 s, about 96K TPM at full occupancy before council traffic),
  not a throttling guarantee. Recompute (2–3 per replica) before enabling a
  lower reasoning effort. Cap-bound latency shows as queue wait with all
  local slots busy and no provider 429/backoff; provider-bound latency shows
  as rising provider duration or throttling.

## Release record

### 2026-09-15 (second release) — commits bb0d52b, c674d35, 58c7baf, 40eebd9, images `buddy:40eebd9` / `buddy:40eebd9-octane`

- Worker revision `ca-buddy-worker-credit--cap-40eebd9` (18:51:37 UTC, Horizon
  started; evaluations capacity 5, maxReplicas 3, rule targets 5 / 1), API
  revision `ca-buddy-api-credit--cap-40eebd9`, four jobs on `buddy:40eebd9`,
  new job `caj-buddy-queue-health-credit` (cron `*/15 * * * *`, first run
  Healthy at 18:55:43), alerts deployment `alerts-cap-40eebd9` Succeeded
  (what-if: three creates, nothing modified): `alert-ca-buddy-worker-credit-memory`,
  `alert-ca-buddy-worker-credit-scaler-failed`, `alert-buddy-queue-degraded-credit`.
- Dependencies: commonmark advisories closed, framework 13.32, Octane 2.19,
  laravel/ai 0.11.2 (Azure via `/openai/v1`, agent timeout honoured).
- Six real evaluations through the production MCP endpoint, queued
  18:52:29 to 18:53:18 (the client issues calls sequentially, so about ten
  seconds apart): every one claimed within 1 s, runtimes 30–80 s, all
  completed on the new gateway; `buddy:queue:report --since=40m`: 9
  evaluation runs, none failed, runtime p50 45 s, max concurrent 5 (the
  per-replica cap; the sixth call arrived after the first had finished, so
  no scale-out was needed).
- Forced-kill rehearsal and the structural cap are described in the ADR 0014
  amendment.
- Sustained-backlog scale-out on the final template (image `ccc6c69`, the
  final review's missing check): 16 × 90 s synthetic jobs on `evaluations`
  at 19:08:44 UTC; by 19:09:37 replicas were 3 with reserved 15 and pending
  1, exactly the 5 × 3 cap with the sixteenth job waiting; never more than
  15 reserved; drained by 19:12:18; the replicas return to one after the
  300 s cooldown.

### 2026-09-15 — commits e049394 + 1ac8dad, images `buddy:1ac8dad` / `buddy:1ac8dad-octane`

- Worker revision `ca-buddy-worker-credit--lanes-1ac8dad` (18:06:40 UTC
  "Horizon started successfully"; command `php artisan horizon`, 1 vCPU /
  2 GiB, grace 600 s, rules `lane-evaluations` and `lane-council`). API
  revision `ca-buddy-api-credit--lanes-1ac8dad` healthy 18:07:05. Four jobs on
  `buddy:1ac8dad`. `GET /horizon` answers 403 (the 404 seen in the first
  seconds after the switch came from the previous revision).
- Image experiment before release: Horizon boots in the production image;
  8 jobs → reserved 6 / pending 2 within 4 s; about 500 MiB under load;
  SIGTERM with six running deadline-loop jobs → exit 0 after 18 s, all six
  processed, the two waiting jobs untouched.
- Synthetic burst, 14 × 90 s on `evaluations` at 18:09:30: 18:10:01 reserved 6,
  pending 8, replicas 3 (the scaler acted within 30 s, ceil(14 / 6) = 3);
  18:10:23 reserved 14, pending 0; drained by 18:11:44 (2 min 10 s wall clock
  against 21 min serial). Worker working set 150 MiB before, 570–579 MiB
  after, with the pools loaded.
- Three real evaluations through the production MCP endpoint, queued
  18:13:06 / 18:13:17 / 18:13:29: each claimed in the same second (0 s queue
  wait), overlapping intervals (max concurrent 3), runtimes 28 / 54 / 42 s,
  all completed. `buddy:queue:report --since=3h`: 15 evaluation runs, none
  failed, runtime p50 42 s / p95 74 s, max concurrent 3.
- Baseline the change was measured against (14 days before): max concurrent
  runs 1, queue wait p50 2 s / p90 35 s / p95 193 s / max 2,655 s, 11% of runs
  waited over 30 s.
