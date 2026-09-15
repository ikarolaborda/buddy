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

## Release record

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
