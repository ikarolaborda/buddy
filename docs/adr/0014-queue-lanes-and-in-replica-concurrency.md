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

## Amendment (2026-09-16): stop signal and bounded shutdown, after Buddy reviews 01M2MHCXAJH09B7QF75MGFF5V1 and 01M2MJ9KBV2TVVDZFPDT46C8DA

**Observation.** After every worker stop on 2026-09-15 and 2026-09-16 (revision switches and
KEDA scale-ins) the stopped replica logged one Redis `Connection refused` per second for exactly
the 600 s grace period and was then killed with reason `ManuallyStopped`. Redis itself stayed
healthy. Container Apps severs the terminating replica's network path to Redis at the moment it
issues the stop.

**Mechanism, two layers.** The production traces of the stopped replicas (wrapper-less
`chair-9ee7738`, 07:38:49 to 07:48:49Z, and the first wrapper revision `linger-41826c8`) all loop
in `MasterSupervisor::loop() -> processPendingCommands() -> RedisHorizonCommandQueue::pending()`,
never in `terminate()`: the process never received a stop signal. The image inherits
`STOPSIGNAL SIGQUIT` from `php:8.5-fpm-alpine`; the platform sends that signal to PID 1, and a
PID 1 without a QUIT handler (Horizon handles TERM, USR1, USR2 and CONT; a shell traps nothing
by default) drops it, so nothing happened until the SIGKILL at the grace deadline. The second
layer was proven in the production image with an explicit SIGTERM: `MasterSupervisor::terminate()`
begins with `longestActiveTimeout()`, a Redis read, the exception is caught by the master loop and
the supervisors and workers are never signalled (Redis stopped, then SIGTERM: still running after
60 s; Redis reachable: exit in 18 s). The first day's analysis saw only the second layer because
the Docker reproduction used `docker kill -s TERM`, not the image's stop signal.

**Decision.** `docker/production/Dockerfile` sets `STOPSIGNAL SIGTERM` (supervisord and Horizon
both handle it), and production runs Horizon under `docker/production/horizon-entrypoint.sh`
(Bicep command `sh /var/www/html/docker/production/horizon-entrypoint.sh`). The wrapper starts
Horizon in its own process group (`setsid`), traps TERM, INT and QUIT once (a bootstrap trap
remembers a signal that arrives before Horizon is up, later signals cannot restart the deadline),
forwards SIGTERM to the master and directly to every `horizon:work` process, and then either ends
when no worker has been alive for `HORIZON_SHUTDOWN_SETTLE` seconds (10) or kills the whole
group at `HORIZON_SHUTDOWN_GRACE` seconds (240, the evaluator's provider timeout). The workers
lose Redis as well: an idle worker exits within a second (lost connection), a busy one finishes
its current job and exits right after because it cannot ack, so an idle replica stops in about
ten seconds and a busy one when its jobs end. Worker output travels through the supervisor,
which stops polling its pipes once Redis is gone, so the last lines a job logs on a severed
replica never reach the console; PostgreSQL is the only record of what it did. When Redis is
reachable Horizon drains on its own first and the wrapper exits with its status. It logs `horizon-entrypoint:
event=started|shutdown_started|workers_drained|shutdown_forced|exited ...` with the revision and
replica names. The Container Apps grace stays 600 s as the fallback if the wrapper fails.

**What it is not.** Containment, not a delivery fix. A finished job cannot ack Redis because
Redis is unreachable, and a job still running at the budget is killed. Recovery is PostgreSQL's:
a finished task is Completed, and the Redis redelivery (`retry_after` 2400 s, counted from the
reservation, not from the kill) hits `isTerminal()` and is a no-op; an unfinished task's claim
lease (1200 s) expires, then either the redelivery (attempt 2 of 3) re-claims and re-runs the
evaluation or the five-minute outbox relay's `reapExpiredLeases()` marks it Failed once the lease
is more than one lease period expired, so about 2400 to 2700 s after the claim. Councils (lease
2400 s, renewed by heartbeat while they run) never fit the budget and follow the same path about
4800 to 5100 s after their last heartbeat. That was already the fate of a job killed at 600 s.
Whether PostgreSQL and Azure OpenAI egress survive termination is unproven; the wrapper events
and the task outcomes of the next real stops are the evidence to collect.

**Rejected.** Subclassing `MasterSupervisor`: `HorizonCommand` instantiates it directly, so there
is no container binding, `Supervisor::terminate()` reads Redis too, and it is version-sensitive.
Lowering the platform grace alone: loses the healthy 600 s drain window, gives no telemetry and
still depends on the platform kill. Fixing only the stop signal: Horizon would then stall in
`terminate()` on the severed Redis for the whole grace period.

**Evidence (production image, `docker stop`, budgets shortened for speed).** Redis unreachable,
idle: `workers_drained` and exit in 10 s, status 137. Redis severed while five 40 s jobs run:
exit in 46 s, after the jobs. Redis reachable with five running jobs: graceful exit in 18 s,
status 0, reserved set drained. Second signal at +8 s: no restart of the deadline. SIGQUIT:
handled like SIGTERM. Signal one second after start, and twice within the first 300 ms: exit
within 3 s, status 0. Horizon exiting by itself (status 1) and the master being SIGKILLed while
workers run: the wrapper exits at once with the child's status and
`detail=horizon_exited_without_signal`. An invalid budget value falls back to 240 with a
`startup_warning` event.

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
