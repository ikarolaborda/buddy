# Cloudflare edge rollout, budgets and rollback

Operating recipe for the features introduced by the 2026-09-15 plan. Deployment
mechanics for the Worker itself live in
[cloudflare-edge-deployment.md](cloudflare-edge-deployment.md); the scaler
repair lives in [redis-autoscaling-repair.md](redis-autoscaling-repair.md).

## Flags

All seven flags ship `false` in the repository. Since 2026-09-15 production runs
with `EVENTS`, `PROGRESS`, `SUPERVISION`, `AUTO_RECOVERY`, `ARTIFACTS` and
`READ_CACHE` true and `BROWSER_DIAGNOSTICS` false (see the handoff's go-live
table). They are independent; the order below is the only supported enable order.

| Flag | Enables | Depends on |
| --- | --- | --- |
| `BUDDY_EDGE_EVENTS` | Outbox publication of task events to `buddy-events-{env}` | Cloudflare queue and `BUDDY_EDGE_QUEUES_TOKEN` provisioned |
| `BUDDY_EDGE_PROGRESS` | Event recording, `progress` in status responses, view tickets, session exchange, snapshots | Migration applied |
| `BUDDY_EDGE_SUPERVISION` | Delegation minting, delegated status reads, delegated `diagnose_health` | Events and progress on, Worker `SUPERVISION_ENABLED=true` |
| `BUDDY_EDGE_AUTO_RECOVERY` | Delegated `recover_evaluation` | Supervision on and gate G5 passed |
| `BUDDY_EDGE_ARTIFACTS` | Upload reservations, finalization, downloads, deletion, processing | R2 bucket and `BUDDY_R2_*` credentials provisioned |
| `BUDDY_EDGE_READ_CACHE` | Worker-side KV caching of artifact summaries (Worker var `READ_CACHE_ENABLED`) | Artifacts on, measured origin reduction |
| `BUDDY_EDGE_BROWSER_DIAGNOSTICS` | Live capture requests | Gate G7: account-specific Browser Run billing evidence, network boundary tests, owned test page |

Server-side secrets: `BUDDY_EDGE_SERVICE_KEY` (Key Vault `buddy-edge-service-key`,
shared with the Worker as `EDGE_SERVICE_KEY`), `BUDDY_EDGE_DELEGATION_SECRET`
(Key Vault `buddy-edge-delegation-secret`) and `BUDDY_SCALING_METRICS_KEY` (Key
Vault `buddy-scaling-metrics-key`). Azure holds no Cloudflare API token and no
R2 key: events post to the Worker's `/internal/events`, uploads and downloads use
HMAC tokens signed with the service key, and finalization uses the Worker's
`/internal/objects` API. `BUDDY_EDGE_QUEUES_TOKEN` and `BUDDY_R2_*` remain
optional alternatives that switch the publisher and object store back to direct
Cloudflare APIs when configured.

## Budgets and quotas

| Control | Value | Enforced by |
| --- | --- | --- |
| Pilot budget | $25 total, $2 per day, alert at 80%, stop optional work at 100% | `BuddyEdgeBudget` Durable Object counters (`DAILY_BUDGET_*` vars); Cloudflare billing is the reconciliation source |
| Events | 16 KiB per event, 30-day retention | `TaskProgressService` withholds oversized data; `buddy_task_events` pruning is a scheduled job to add before prod enablement |
| Uploads | 25 MiB per upload, 100 MiB per task, 1 GiB per client per day, 10 active reservations | `buddy_artifact_quotas` row locks |
| Retention | 30-day objects, 7-day quarantine, 5-minute upload URLs, 2-minute download URLs | `buddy:artifacts:cleanup`, presign expiry |
| Captures | 10 per client per day, 30 s, one page, 2 concurrent | PostgreSQL counters plus the Worker budget object |
| Sockets | 20 active per task | `BuddyTaskProgress` Durable Object |
| Council context | 16,000 chars per artifact, 160,000 per packet | unchanged `buddy_agents.council.*` |

Credit expiry: the Cloudflare grant expires 2027-08-11T13:01:00.902Z. Create
alerts at 60, 30, 14 and 7 days before; decide renewal or shutdown by
2027-07-28; disable optional work before expiry unless payment is authorized.
Storage keeps costing after flags turn off; the expiry runbook must cover
export, retention, deletion approval and removal of idle billable resources.

## Enable order (per environment)

1. Prove P0 (runbook) and deploy the image with all flags off.
2. Apply migrations (`caj-buddy-migrate-credit`), confirm `/api/ready`.
3. Provision preview Cloudflare resources; run Worker contract tests against
   the preview URL with a throwaway client.
4. `BUDDY_EDGE_PROGRESS=true`, then `BUDDY_EDGE_EVENTS=true` for one owned
   pilot client; compare Durable Object projections with PostgreSQL for a day.
5. Enable the dashboard (Worker deployed, `ALLOWED_ORIGIN` set) and observe
   healthy and failed connections, reconnects, stale states, polling fallback.
6. `BUDDY_EDGE_SUPERVISION=true` (diagnosis only); then
   `BUDDY_EDGE_AUTO_RECOVERY=true` only after G5.
7. `BUDDY_EDGE_ARTIFACTS=true` for the pilot client; then `READ_CACHE_ENABLED`
   after measuring at least 50% fewer repeated origin payload reads.
8. Browser Run only after G7.

## Rollback (flags off is not enough)

1. Stop new supervisor actions first: Worker `SUPERVISION_ENABLED=false`,
   `AUTO_RECOVERY_ENABLED=false`; then `BUDDY_EDGE_AUTO_RECOVERY=false` and
   `BUDDY_EDGE_SUPERVISION=false` on Azure so delegated callbacks 404.
2. Stop remote deliveries: `BUDDY_EDGE_EVENTS=false`. Pending
   `outbox_deliveries` rows stay recoverable; the relay defers them while the
   flag is off and `buddy:outbox-replay` can replay them later.
3. Cancel or let drain affected Workflow instances (`wrangler workflows
   instances terminate` for the supervisor only); never cancel Azure model work.
4. Expire browser sessions: `BUDDY_EDGE_PROGRESS=false` makes every internal
   route 404 and view tickets stop minting; existing sessions fail closed on
   their next authorization.
5. Reconcile uploads: `buddy:artifacts:cleanup` expires reservations; ready
   objects stay retained with their metadata.
6. Keep queues, outbox records, inbox records, audit data and the additive
   schema. Do not run destructive migrations or delete storage as a shortcut.
7. Only after compatibility checks, redeploy the previous application revision.

## Fault exercises before production enablement

Cloudflare outage (queue 5xx): task submission returns 201/202, evaluation
proceeds, `outbox_deliveries.next_attempt_at` backs off, relay recovers within
120 s of restoration. Azure outage: Durable Object marks stale, dashboard shows
"update delayed", no recovery is attempted. Redis loss: worker restarts, tasks
reclaim through leases, scaler signal reads 0. Consumer crash after Durable
Object acceptance but before ack: redelivery is a no-op by sequence. Duplicate
and reordered events: projection unchanged. Expired credentials: every internal
route 401/404, no cached payload becomes readable. Exhausted budget: optional
work stops, terminal events and cleanup keep their reserve.
