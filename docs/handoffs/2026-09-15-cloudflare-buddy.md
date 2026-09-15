# Claude handoff: Buddy Cloudflare implementation

Date: 2026-09-15. Task: plan all six Cloudflare improvements and preserve a handoff and memories.
The planning deliverables are complete. Feature implementation starts after this handoff.

## Read first

Read the [full implementation plan](../plans/2026-09-15-cloudflare-buddy-usability-performance.md).
It contains P0–P8, proposed contracts, source files, acceptance gates, costs, rollout, and rollback.
Read the [current council and interventions guide](../recipes/azure-council-and-interventions.md) for shipped behavior.
Read [ADR 0010](../adr/0010-caller-curated-context-boundary.md) before extending evidence collection.

Search shared Qdrant memory with project `buddy` and query `Cloudflare implementation plan September 15 2026 P0 P8`.
The planning episode ID is `1ebdbcd0-5659-500e-bca6-9b0beff01436`.
The repository documents remain sufficient if memory is unavailable.
Historical guide examples do not override the current remote-only production evaluation rule.

## Requested scope and decisions

The user requested all six capabilities: Workers with Durable Objects, Workflows, R2, Queues, KV, and Browser Run.
The plan also includes the verified Redis autoscaling fault as P0.
The current deliverable is a plan, memory, and Claude-readable handoff.
No new Cloudflare application feature or Azure infrastructure change belongs to this documentation change.

Preserve these decisions:

- Run real Buddy evaluations through `https://buddy.aerolambda.tech/api/mcp` on Azure.
- Do not use a Pointerpro or local Buddy container for real evaluations.
- Keep the Azure default council: Astra `xhigh` chairman and three GPT-5.5 `high` reviewers.
- Preserve Azure's `startup-credit-ai-only` policy and its allowed deployments.
- Keep `BUDDY_COUNCIL_WORKERS_AI_SOL=false` on the API and worker.
- Keep Sol and Terra out of active routing while Azure policy excludes them.
- Keep Fable 5.1 and paid AI Gateway routes disabled under the credit-only constraint.
- Keep GLM-5.3 out of shipped rosters after its timeout failures.
- Limit interventions to diagnosis and eligible failed-evaluation recovery.
- Preserve each machine's `.env`, database, and original database archives.

The three GPT-5.5 reviewers are separate roles, not independent model families.
Local PHPUnit tests with AI and HTTP fakes remain allowed.
Cloudflare startup coverage does not remove task ownership or authorization requirements.

## Source and runtime baseline

Application source at planning time: `aa2c1920c57f92a8e93576b46795eaa85cacc03f`.
Local, origin `main`, and M5P matched this clean source before the documentation change.
The previous comparison found all 367 tracked files identical on M5P.
Later documentation commits can advance source without changing the application image.

| Resource | Identity |
| --- | --- |
| Local checkout | `/Users/ikarolaborda/Aerolambda/buddy` |
| Git origin | `git@github.com:ikarolaborda/buddy.git` |
| M5P SSH | `ikaros-macbook-pro-m5p.local` |
| M5P checkout | `/Users/ikarolaborda/Aerolambda/buddy` |
| Azure subscription | `e7e7a0f4-2689-47ed-b7e0-ce68d8394cc4` |
| Resource group | `rg-buddy-credit` |
| Registry | `acrbuddycreditoerh7kdnhtzo6.azurecr.io` |
| API revision and image | `ca-buddy-api-credit--sol-aa2c192`, `buddy:aa2c192-octane` |
| Worker revision and image | `ca-buddy-worker-credit--sol-aa2c192`, `buddy:aa2c192` |
| Cloudflare account | `63cc5315181fb5f7fbf59dac3efcf76e` |
| Public health | `https://buddy.aerolambda.tech/api/health` |

API and worker were running, repeated health probes passed, and migration execution `caj-buddy-migrate-credit-gomzs03` succeeded.
CI run `34979750226` passed 290 SQLite tests with 969 assertions and 55 PostgreSQL tests with 300 assertions.
Three PostgreSQL-only tests were skipped in the SQLite job.
Do not rerun paid model probes merely to validate these documentation files.

## First implementation task: P0

At 2026-09-15 14:26:52 UTC, the current worker revision reported:

```text
KEDAScalerFailed
connection to redis failed: dial tcp 100.100.226.25:6379: connect: connection refused
```

Begin with read-only evidence:

```bash
cd /Users/ikarolaborda/Aerolambda/buddy
git status --short --branch
git fetch origin
git rev-parse HEAD origin/main
az containerapp logs show \
  --subscription e7e7a0f4-2689-47ed-b7e0-ce68d8394cc4 \
  --resource-group rg-buddy-credit \
  --name ca-buddy-worker-credit \
  --type system --tail 30 --format json
az containerapp show \
  --subscription e7e7a0f4-2689-47ed-b7e0-ce68d8394cc4 \
  --resource-group rg-buddy-credit \
  --name ca-buddy-worker-credit \
  --query '{revision:properties.latestReadyRevisionName,scale:properties.template.scale}' \
  --output json
curl --fail --silent --show-error https://buddy.aerolambda.tech/api/health
```

Use `--format json` for system logs. The CLI rejected `--format text` during the investigation.
Inspect only the selected configuration fields. Do not print complete application environments or secret values.

Inspect `infra/azure/modules/buddy-worker.bicep`, `redis-container.bicep`, `config/database.php`, and `config/queue.php`.
The scaler currently names `buddy:queue:default`.
Measure the actual Redis database, Laravel prefix, and pending list before changing that value.
Separate worker connectivity from the KEDA control path.
Do not expose Redis publicly or trust the historical short-hostname fix without current evidence.

Preserve API 1–5 replicas and worker 1–4 replicas until an evidence-based change is ready.
Keep at least one replica during the startup milestone window, approximately through 2026-10-10.
Keep council timeout 1,800 seconds, worker timeout 1,860 seconds, and Redis retry and council lease 2,400 seconds.
Use synthetic jobs without inference for the scale-out experiment.

## Traps that the plan addresses

`OutboxPublisher::dispatchFor()` has a fallback that can dispatch evaluation for an unknown topic.
Replace it with explicit topic handling before adding progress or artifact events.
Completion events also need publication after a task becomes terminal.

Task claims and `state_version` belong to PostgreSQL.
Durable Objects hold replaceable progress projections and cannot authorize recovery or overwrite a lease.
Allocate event sequence numbers separately from task claim versions.

Council heartbeats occur between rounds.
A quiet interval during a model call does not establish worker failure.
Council transcripts are audit checkpoints, not a resumable council executor.

Azure background response IDs currently remain process-local.
Do not blindly repeat a potentially accepted inference after a crash.
Any resumable provider operation needs persisted identity, ownership, deadlines, and cleanup rules.

`InterventionService` already enforces ownership, trusted transient failure, one recovery child, and no recovery chains.
Workflow retries must use that service and stable request IDs.
Refusals, credential restrictions, live tasks, and council reruns do not qualify.

R2 storage must not silently enlarge the model context.
Current council limits are 16,000 characters per artifact and 160,000 characters per packet.
Keep original objects separate from bounded extracted evidence.

## Cloudflare coverage and access

The grant was $10,000 and expires at `2027-08-11T13:01:00.902Z`.
The last observed balance was $9,999.65. Read billing again before activation.
The published Tier 3 Workers AI cap is $2,500, while the account-specific cap was not separately returned.
AI Gateway is excluded from published startup coverage.
Browser Run coverage remains unconfirmed, so its live flag stays off until the plan's G7 gate passes.

The local `.env` contains an admin Cloudflare token.
Use it only through secret-safe account operations and preserve its local restrictions.
Never copy it to Azure or expose it in a handoff, log, or browser.
The deployed token `buddy-azure-workers-ai-20260915` has Workers AI permissions only.
Azure uses Key Vault `kv-buddy-credit`, secret `buddy-cloudflare-workers-ai`, through managed identity.
Provision separate limited credentials for the new services.

The worker has OpenRouter configured, but the API does not.
This fact does not authorize Sol activation or separate paid inference.
Native GPT-OSS catalog requests succeeded, but they do not establish council quality.

Current DNS uses `ns49.domaincontrol.com` and `ns50.domaincontrol.com`.
The Buddy CNAME points directly to Azure Container Apps.
Use a separate `workers.dev` pilot before considering a new custom hostname.

Existing memory resources include `qdrant-memory-cloud-sync`, `qdrant-memory-jobs-v1`, and its dead-letter queue.
They also include memory workflows, Durable Objects, and the `buddy-memory-sync` R2 bucket.
Create separate Buddy application resources and preserve the existing memory service.
Do not enable its scheduled Autopilot as part of this work.

## Implementation status (2026-09-15, on `main`)

Application source at the start of implementation: `0be2c79` (docs) on `aa2c1920c57f92a8e93576b46795eaa85cacc03f`.
Commits on `main`: `37c6bb6` (P0 signal repair + P1-P3 Azure foundation), `211d2af` (R2 disk, route surface, capabilities), `90565fa` (IaC edge wiring, cleanup job, rollout recipe, evidence manifest), `17fa5aa` (P4 delegated supervision + diagnostics observations + PostgreSQL edge suites), `7545506` (secret name limit), `2e460e8` (P7 browser diagnostics), `30846aa` (P5 artifacts), `e29d9f0` (Docker context), `ed62db4` (Worker package). Local, `origin/main` and M5P were fast-forwarded to `ed62db4`.

### Deployed to Azure on 2026-09-15 (image tag `e29d9f0`, all edge flags false)

| Item | Identity |
| --- | --- |
| Images | `buddy:e29d9f0` (worker, jobs), `buddy:e29d9f0-octane` (API); ACR run `cg1p` |
| Migrations | execution `caj-buddy-migrate-credit-38qabuv`, three additive migrations DONE |
| API revision | `ca-buddy-api-credit--edge-e29d9f0`, Healthy, 100% traffic; secrets +`scaling-metrics-key`, `edge-service-key`, `edge-delegation`; `BUDDY_EDGE_*=false`, `BUDDY_SCALING_METRICS_KEY` set |
| Worker revision | `ca-buddy-worker-credit--edge-e29d9f0`, Healthy, 1-4 replicas; single scale rule `queue-depth-api` (`metrics-api`); secrets +`scaling-metrics-key` |
| Jobs | `caj-buddy-outbox-credit`, `caj-buddy-feedback-health-credit` on `e29d9f0`; new `caj-buddy-artifacts-credit` (daily 03:15 UTC, `buddy:artifacts:cleanup`) |
| Verified | `/api/health` ok, `/api/ready` ready, queue-depth endpoint 200 with key and 401 without, capabilities reports every flag false |

Rollback: redeploy `buddy:aa2c192-octane` / `buddy:aa2c192` with the previous revision definitions (the pre-change worker export lives outside the repository); keep the additive schema. No model probe ran. Cloudflare preview resources were not created (declined during the session); the Worker package is deployable with `wrangler deploy --env preview` once resources exist.

### P0 (repair Redis autoscaling) — diagnosed, IaC fixed, live switch deferred to the release

- Measured inside the worker: prefix `laravel-database-`, queue `default`, db 0. The real key is `laravel-database-queues:default`; the rule watched `buddy:queue:default`.
- The scaler cannot reach Redis at all: 1,469 `KEDAScalerFailed` events in 14 days on every revision (`connection refused` to 100.100.226.25:6379), while worker replicas connect to the same address. The internal FQDN does not serve 6379 either (probe app: `i/o timeout`). The platform scaler does reach public HTTPS (probe app with a `metrics-api` rule got HTTP 403 from GitHub).
- Decision [ADR 0012](../adr/0012-worker-autoscaling-signal.md): keep the corrected key (`redisQueueListName`, pinned by `tests/Unit/RedisQueueScaleRuleTest.php`) and move the worker scale signal to `workerScaleRuleType=metrics-api` against `GET /api/internal/scaling/queue-depth` (`X-Buddy-Scaling-Key`, Key Vault `buddy-scaling-metrics-key`, already created).
- Why no live change yet: a key-only revision changes nothing while the scaler cannot dial Redis, and the endpoint needs the new image. Procedure, evidence and rollback: [redis-autoscaling-repair.md](../recipes/redis-autoscaling-repair.md). G1 scale-out proof (`buddy:queue:synthetic --confirm`) runs at the release.

### P1-P3 (contracts, transport, progress) — implemented on Azure, flags off

- `config('buddy.edge.*')`: seven flags default false plus limits, budgets, quotas, retention. Migration `2026_09_16_100000_create_buddy_edge_foundation_tables` is additive.
- Outbox: explicit topic registry; unknown topics are quarantined and can never dispatch evaluation; per-destination `outbox_deliveries` with claims and backoff; Cloudflare Queues publication only from `DeliverOutboxRemoteJob` or the relay; `buddy:outbox-replay` (remote only, audited).
- Identity: single-use view tickets (`POST /api/buddy/tasks/{task}/view-tickets`), atomic ticket exchange to hashed sessions, delegations (`bdg1.` HMAC tokens bound to client, task, generation, scopes, expiry; revocation fails closed), `/api/internal/cloudflare/*` behind `X-Buddy-Edge-Key`, every route 404 while its flag is off.
- Progress: `buddy_task_events` with a per-task sequence allocated under the task row lock; phases queued, memory, evaluation, council.frame/positions/attacks/verdict (with the real roster), terminal, recovery; `progress` object in REST and MCP status only when `BUDDY_EDGE_PROGRESS=true`.
- Public `GET /api/buddy/capabilities` exposes schema version, flags and limits only.

### P4-P7 and the Worker package

Implemented in parallel on this branch (see the pull request for the file list and test totals): P5 artifact storage service, uploads, quotas, processing and cleanup (`buddy:artifacts:cleanup`); the `cloudflare/buddy-edge` TypeScript Worker (Durable Object projection with hibernating WebSockets, queue consumers, supervisor Workflow, KV summary cache, budget counters, mock-only browser capture); P4 delegation minting, delegated status reads and diagnostics observations; P7 `diagnostics:capture` scope, target policy, capture records and [ADR 0013](../adr/0013-bounded-browser-diagnostics.md). All live behavior stays behind flags that ship false.

### Infrastructure and secrets

- Bicep: API, worker and jobs carry explicit `BUDDY_EDGE_*=false` env, Key Vault references for the edge service key and delegation secret, optional (`deployEdgeSecrets`) references for the Queues token and R2 keys, and a daily `caj-buddy-artifacts-{env}` cleanup job. `az bicep build` passes.
- Key Vault `kv-buddy-credit`: `buddy-edge-service-key`, `buddy-edge-delegation-secret`, `buddy-scaling-metrics-key` created with random values on 2026-09-15 (unused until the release). Still to provision by an operator: `buddy-cloudflare-queues` (Queues write token), `buddy-r2-access-key-id`, `buddy-r2-secret-access-key` (R2 token scoped to `buddy-artifacts-*`).
- The local `CLOUDFLARE_API_TOKEN` in `buddy/.env` is a valid, active account-owned token (verify it with `GET /accounts/{account_id}/tokens/verify`; the user-scoped verify endpoint wrongly reports `Invalid API Token` for account tokens). Wrangler also holds an OAuth login for `iclaborda@aerolambda.tech` (account `63cc5315181fb5f7fbf59dac3efcf76e`), which provisioned the edge resources. Commands are listed in [cloudflare-edge-deployment.md](../recipes/cloudflare-edge-deployment.md).

### Go-live (2026-09-15, second session)

The user asked for no dark deploy, so the edge is live. Commits `e60e8b1` (credit report, resources), `230c030` (Worker-mediated transport: Azure holds no Cloudflare token or R2 key), `57688e3`/`dd27b2b` (Worker ingestion, signed upload/download tokens, object API), `010a37e` (Worker runs before assets so the dashboard carries security headers), `1b5afdb` (null trace id accepted).

| Item | State |
| --- | --- |
| Cloudflare resources | queues `buddy-events-{preview,prod}` + DLQs, `buddy-artifacts-{preview,prod}` + DLQs; R2 `buddy-artifacts-{preview,prod}`; KV `BUDDY_READ_CACHE_{PREVIEW,PROD}`; ids in `infra/cloudflare/README.md` |
| Workers | `buddy-edge-preview` and `buddy-edge-prod` on `iclaborda.workers.dev`, secret `EDGE_SERVICE_KEY` from Key Vault; production vars `SUPERVISION_ENABLED`, `AUTO_RECOVERY_ENABLED`, `READ_CACHE_ENABLED` true, `BROWSER_DIAGNOSTICS_ENABLED` false |
| Azure | image `230c030`; API `ca-buddy-api-credit--flags-230c030`, worker `ca-buddy-worker-credit--flags-230c030`, all four jobs on `230c030`; `BUDDY_EDGE_WORKER_URL`/`ALLOWED_ORIGINS` = the prod Worker; flags `EVENTS`, `PROGRESS`, `SUPERVISION`, `AUTO_RECOVERY`, `ARTIFACTS`, `READ_CACHE` true; `BROWSER_DIAGNOSTICS` false |
| Verified end to end | real evaluation task `01M2K0H9ZYC7EZ2S4N87XRDTPG`: four events (queued, memory, evaluation, completed) reached the Worker queue after the trace-id fix (13 pending deliveries replayed, 0 failed), Durable Object counters events 26 / callbacks 1 / workflows 1, supervisor instance `sup-01M2K0M082F57RC6KCKVQ4MQHH-1` completed; dashboard opened in Chrome through a view ticket showing the timeline, queue wait 1 s, model time 15 s; artifact 97 reserved, uploaded through the Worker, finalized (sha256), summary redacted, downloaded byte-identical, deleted and revoked |
| Verification client | `edge-verifier` (client #12), key expires 2026-09-22; revoke earlier with `ApiKeyService::revoke` if unwanted |
| Still off | `BUDDY_EDGE_BROWSER_DIAGNOSTICS`: Browser Run is not in the startup credit coverage list; the Workers Paid plan includes 10 browser hours per month and the pilot quota needs about 2.5, so enabling it costs nothing extra but is a billing decision the founder must take (docs/releases/2026-09-15-ai-model-credit-coverage.md) |

### Day-two checks (from the final review, buddy task 01M2K17TPDNP7V63F4Z2M3JNY9)

1. Deployment drift and negative probes: confirm the Azure revisions (`flags-230c030`), Worker versions and non-secret flag values; run wrong-key, wrong-environment, expired-token, relabelled-token and oversized-upload probes only against a disposable reservation on a throwaway task; assert rejection without mutation.
2. Overnight delivery correctness: `buddy:outbox-replay --dry-run` via a one-off job execution for pending or failed remote deliveries, queue backlog and DLQ depth (`wrangler queues list`), Worker budget counters (`/internal/budget`, two units per event by design), supervisor instances; run one tagged canary task end to end.
3. Spend and artifact integrity: compare Cloudflare and Azure usage with the counters and the credit terms in the coverage report; check outstanding reservations and staging bytes (`buddy:artifacts:cleanup --dry-run`).
Follow-up recorded: split the single shared edge key into separate event, object and token-signing credentials with overlapping rotation.

### Cloudflare preview

Preview resources exist alongside production (table above); `buddy-edge-preview` passes the same fourteen-check smoke test as production and is the place to try Worker changes before `wrangler deploy --env production`.

### Gates

G0 passed. G1 passed: key fix and metrics-api signal live; the synthetic 30-job backlog scaled the worker from one to three replicas within about 90 seconds, drained at about two jobs per minute, scaled back to one replica, and every job ran exactly once (runbook and evidence manifest). G2, G3, G5 (fakes and PostgreSQL suites), G6 (fake object store) pass locally. G4 depends on the Worker tests. G7 stays open: Browser Run coverage under the startup grant remains unconfirmed and `BUDDY_EDGE_BROWSER_DIAGNOSTICS` stays false. G8: rollout, budgets and rollback are documented in [cloudflare-edge-rollout.md](../recipes/cloudflare-edge-rollout.md); the production rollout itself has not been performed. Do not describe the six capabilities as complete while G1, G7 and the production rollout remain open.

## Queue harness (2026-09-15, third session) — LIVE

Agents reported Buddy "takes too long when several of us call it". Measured
over 14 days: two runs never overlapped (one `queue:work` process per replica,
councils and evaluations on one list, scaler threshold 10). Shipped and
deployed as [ADR 0014](../adr/0014-queue-lanes-and-in-replica-concurrency.md)
with the release and rollback procedure in
[queue-lanes-release.md](../recipes/queue-lanes-release.md): Redis stays the
transport (Kafka/RabbitMQ rejected on evidence), queue lanes
`evaluations` / `council` / `fast` / `legacy`, Laravel Horizon with one
fixed-size supervisor per lane (6 / 1 / 2 / 1 per replica), per-lane scaling
signal with two KEDA rules, worker at 1 vCPU / 2 GiB with a 600 s grace
period, and `php artisan buddy:queue:report` for the wait / runtime /
concurrency evidence. Verified in production: 14 synthetic jobs ran 14-wide
across three replicas; three real evaluations submitted together were
claimed in the same second and ran concurrently.

Final review (Buddy 01M2K4KNK1EMNJS8YWW23YQJRT, accepted, medium): the
evidence supports "Buddy no longer serializes multiple callers at the queue",
not a production p95 guarantee or a saturation limit. Its day-two checks:
run a six-call simultaneous burst of real evaluations through MCP with a
declared credit budget and record client-perceived time, wait, runtime,
outcomes and throttling; rehearse SIGTERM, grace expiry and forced kill for
evaluation and council jobs in an owned environment before touching the
grace period or timeouts; keep the expand/migrate/contract order for any
scale-rule contract change; add lane-level monitoring (oldest ready-job age,
counts, failed/retried jobs, scaler errors, replicas, provider throttling).

Founder-facing follow-ups: (1) the evaluator's reasoning effort is still
unsent (measured 5× latency lever) and should go through a quality-gated CIL
replay before promotion (Buddy 01M2K4C8EJ0VAB9AKW07C2ETMP); (2) add a
deployment-wide provider funnel if Azure OpenAI 429s appear (pressure above
about 16 concurrent evaluations on `gpt-6-astra`); (3) a forced kill of an
in-flight council during scale-in has not been rehearsed; (4) rollback keeps
the all-lane worker and reverts routing through `BUDDY_QUEUE_*=default`.

## Resume and finish procedure

1. Read local instructions and the current repository status.
2. Search shared memory under project `buddy` with the query near the top of this handoff.
3. Recheck the current runtime and P0 fault with the commands above.
4. Implement one work package from the plan and keep its feature flags off until its gate passes.
5. Run focused tests and PostgreSQL concurrency tests when ownership or delivery changes.
6. Record tests, source SHA, deployed identities, remaining gates, and rollback state in this handoff.
7. Synchronize committed source to M5P without copying secrets or databases.
8. Store completed findings in shared memory with links to the versioned documents.

On M5P, read `/Users/ikarolaborda/Aerolambda/AGENTS.md` before code changes.
Its existing graph index uses `/Users/ikarolaborda/.local/bin/graphify` and `graphify-out/graph.json`.
Follow its graph query and update instructions when applicable.
Do not normalize live Claude configuration or rewrite unrelated project entries.

No additional user input is required to read this plan or investigate P0.
Account-specific Browser Run billing evidence remains a real activation dependency.
Do not report all six features as implemented until every applicable gate passes.
