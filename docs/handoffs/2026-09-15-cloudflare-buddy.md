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
