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

## Release record

Appended per release with image tag, revisions, verification output and the
`buddy:queue:report` before/after figures.
