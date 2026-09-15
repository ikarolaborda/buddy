# ADR 0014: Queue lanes and in-replica concurrency

**Status:** Accepted — 2026-09-15

## Context

Agents reported that Buddy "takes too long when several of us call it at
once". Fourteen days of production `buddy_runs` (2026-09-01 to 2026-09-15,
463 runs from 6 clients) measured where the time goes:

| Measure | Value |
| --- | --- |
| Queue wait (job accepted → worker claim) p50 / p90 / p95 / max | 2 s / 35 s / 193 s / 2,655 s |
| Runs that waited more than 30 s | 51 of 457 (11%) |
| Evaluation runtime p50 / p90 / p95 / max | 56 s / 154 s / 236 s / 241 s |
| Council runtime p50 / max | 417 s / 739 s |
| Runs started within 60 s of the previous run | 27 |
| Maximum number of runs executing at the same time | **1** |

Two runs never overlapped in two weeks. The worker container ran one
`queue:work` process per replica, every job (evaluations, councils, outbox
deliveries, artifact processing) went to the single `default` list, and the
autoscaler, repaired the same day (ADR 0012), only adds a replica at ten
pending jobs. A burst of two to five agents therefore serialized behind
each other, and behind any council in front of them. When the worker was
idle, Redis handed a job over in 1–4 s, so the broker itself was never the
bottleneck.

The request named Redis, Kafka and RabbitMQ as candidate "harnesses".

## Decision

1. **Redis stays the transport.** PostgreSQL is the authority for tasks,
   claims, leases and recovery; the transactional outbox re-dispatches what
   Redis loses and the supervision workflow recovers stalled tasks. Kafka
   (Azure Event Hubs) or RabbitMQ would add a third-party Laravel driver, a
   new managed service and a second delivery model, and neither adds a single
   concurrent consumer. The measured broker latency gave nothing to fix.
2. **Queue lanes.** Every job pins itself to a lane in its constructor
   (`config('buddy.queues.lanes')`): `evaluations` for `EvaluateTaskJob`,
   `council` for `CouncilDeliberateJob`, `fast` for outbox deliveries,
   knowledge prefetch, artifact processing and purge, and diagnostic
   capture dispatch. `legacy` is the old `default` list, still consumed so an
   older API revision's jobs drain, and the rollback target for routing.
   `tests/Feature/QueueLanesTest` fails when a queued job is not in the lane
   map, when a dispatch site pushes to `default`, or when the Bicep targets
   drift from the configured capacities.
3. **Laravel Horizon runs one fixed-size supervisor per lane** in the worker
   replica: 6 evaluation processes, 1 council, 2 fast, 1 legacy
   (`config/horizon.php`, `BUDDY_WORKERS_*`). A council can no longer stand
   in front of an evaluation, and six agents are served at once by a single
   replica. Pools are fixed rather than auto-balanced so a burst never waits
   for Horizon to grow the pool. Supervisor timeouts sit above the job
   timeouts of their lane and below `retry_after`; the legacy supervisor
   uses the council ceiling. Horizon bookkeeping uses a distinct prefix and
   is trimmed within the hour because Redis is a 256 MB `noeviction`
   instance. The dashboard is closed outside local environments.
4. **The scaling signal is per lane.** `/api/internal/scaling/queue-depth`
   reports pending, delayed and reserved counts per lane and a `scaling`
   block: `evaluations` = evaluation demand plus legacy demand, `council` =
   council demand, where demand is pending plus reserved (delayed retries are
   not runnable). Two KEDA `metrics-api` rules read those values with targets
   equal to the lane capacity, so replicas = max(ceil(evaluations / 6),
   ceil(council / 1)), between 1 and 4.
5. **Worker template.** 1 vCPU / 2 GiB for ten worker processes plus the
   Horizon master, and `terminationGracePeriodSeconds: 600` so a scale-in or
   revision switch lets every evaluation that respects its 240 s provider
   timeout finish. Horizon forwards SIGTERM to its workers and waits for
   them. A council or a pathological job outlives the grace period and is
   recovered through its lease, which is already the designed path.

## Amendment (2026-09-15, same day, after Buddy review 01M2K5N6MJHR4WTHKET2EC1Z8Q)

- **Structural provider cap instead of a job funnel.** A blocking Redis
  funnel on the evaluation job was rejected: a limiter wait occupies a worker
  slot, every release consumes one of the job's three attempts, and the
  council would sit outside the cap. The cap is structural: evaluations
  capacity 5 per replica × worker `maxReplicas` 3 = 15 concurrent
  evaluations, about 90K tokens per minute against the 100K quota, leaving
  room for a council. Both numbers live in Bicep and `config/buddy.php` and
  are pinned together by tests. This is steady-state sizing (reasoning
  tokens are already inside the measured completion usage), not a
  throttling guarantee: synchronized starts can still hit a short Azure
  window, and a lower reasoning effort shortens runtimes and raises request
  turnover, so the cap must be recomputed before that lever is pulled.
- **Shutdown rehearsed.** In the production image with an isolated Redis
  (retry_after shortened to 30 s only there): SIGKILL of a worker holding 6
  evaluation and 1 council synthetic jobs left every reserved entry in Redis;
  a replacement worker picked up the waiting jobs within 10 s; after
  retry_after the killed jobs were redelivered. A redelivered job with
  `Tries(1)` (the council) is failed on arrival, which triggers its `failed()`
  path and the supervision recovery rather than a silent re-run (ADR 0009);
  `EvaluateTaskJob` (`Tries(3)`) re-executes behind its PostgreSQL claim.
- **Lane-level health.** `buddy:queue:health` runs every fifteen minutes
  (`caj-buddy-queue-health`) and logs `BUDDY_QUEUE_DEGRADED` when an accepted
  task has waited more than 300 s or more than three evaluations failed in
  the last hour; Azure alerts fire on that marker, on `KEDAScalerFailed`
  bursts and on worker memory above 85%. The scaling endpoint now also
  reports `waiting` per operation from PostgreSQL.
- **Reasoning effort is now sendable but unset.** `EvaluatorOptimizerAgent`
  implements `HasProviderOptions`; `BUDDY_EVALUATOR_REASONING_EFFORT`
  (low, medium, high) reaches the Responses API body, proven by an
  `Http::fake` assertion, and an unset value leaves the request untouched.
  Enabling it in production waits for a CIL replay of recommendation quality.

## Consequences

- Concurrency per replica rises from 1 to 6 evaluations plus 1 council plus
  2 housekeeping jobs; across four replicas 24 evaluations. Azure OpenAI
  `gpt-6-astra` allows 100 requests and 100,000 tokens per minute; an
  evaluation spends roughly 6,000 tokens, so throttling (HTTP 429, retried
  as transient) becomes possible above about 16 simultaneous evaluations.
  A deployment-wide provider funnel is the next step if 429s appear.
- Ten idle worker processes hold ten PostgreSQL connections per replica
  (server limit 429) and roughly 650 MB of memory.
- `php artisan buddy:queue:report` reports queue wait, runtime, failures and
  maximum concurrency for any window from `buddy_tasks.queued_at`,
  `buddy_tasks.worker_started_at` and the run intervals, so the claim above
  can be re-checked at any time.
- Rollback keeps the all-lane worker and reverts routing by pointing the
  three lane variables at `default` on the API and jobs; reverting the worker
  alone would strand jobs already serialized onto the new lanes. See
  `docs/recipes/queue-lanes-release.md`.
- Deployed 2026-09-15 (`docs/recipes/queue-lanes-release.md`, release
  record): a 14-job synthetic burst ran 14-wide across three replicas within
  a minute, and three real evaluations submitted together were each claimed
  in the same second and ran concurrently. The 75-second scaler failure
  window caused by deploying the worker before the API taught the
  expand/migrate/contract rule recorded in the recipe.
- The evaluator's reasoning effort is untouched. It was measured on
  2026-09-06 as a 5.2× latency lever at lower reasoning depth and remains a
  product decision, not a queue decision.
