# Cloudflare resource manifest (buddy-edge)

Declarative source of truth for the Cloudflare resources used by `cloudflare/buddy-edge`. This file records
names and binding names only. It contains no secrets, no account ids and no API tokens. Resource ids that are
not secrets (KV namespace ids) are recorded here once the resources exist; until then the placeholders in
`cloudflare/buddy-edge/wrangler.jsonc` stay in place.

Provisioning method (ADR to be recorded): `wrangler` commands executed by an operator, listed in
`docs/recipes/cloudflare-edge-deployment.md`. Nothing in this repository creates resources automatically.
Preview credentials must not be able to read or modify production resources.

## Workers

| Environment | Worker name | Route | Entry |
| --- | --- | --- | --- |
| preview | `buddy-edge-preview` | `https://buddy-edge-preview.<account-subdomain>.workers.dev` | `src/index.ts` |
| production | `buddy-edge-prod` | `https://buddy-edge-prod.<account-subdomain>.workers.dev` | `src/index.ts` |

`workers.dev` is used for the pilot because the `aerolambda.tech` zone is on GoDaddy nameservers. A custom
hostname needs a separate DNS design.

## Bindings and resources

| Binding | Kind | Preview | Production | Purpose |
| --- | --- | --- | --- | --- |
| `BUDDY_TASK_PROGRESS` | Durable Object, class `BuddyTaskProgress` (SQLite, migration `v1`) | namespace created on first deploy | namespace created on first deploy | One projection per client and task: replay buffer, tickets, hibernating sockets |
| `BUDDY_EDGE_BUDGET` | Durable Object, class `BuddyEdgeBudget` (SQLite, migration `v1`) | namespace created on first deploy | namespace created on first deploy | Global daily counters, capture concurrency, reconciliation items |
| `BUDDY_EVENTS` | Queue (producer + consumer) | `buddy-events-preview` | `buddy-events-prod` | Progress, terminal, recovery envelopes from Azure |
| – | Dead-letter queue | `buddy-events-dlq-preview` | `buddy-events-dlq-prod` | Messages that exhausted 5 retries |
| `BUDDY_ARTIFACT_EVENTS` | Queue (producer + consumer) | `buddy-artifacts-preview` | `buddy-artifacts-prod` | Artifact and export envelopes, isolated from progress |
| – | Dead-letter queue | `buddy-artifacts-dlq-preview` | `buddy-artifacts-dlq-prod` | Poison artifact messages |
| `BUDDY_TASK_SUPERVISOR` | Workflow, class `BuddyTaskSupervisor` | `buddy-task-supervisor-preview` | `buddy-task-supervisor-prod` | Bounded health supervision, one instance per task generation |
| `BUDDY_READ_CACHE` | KV namespace | title `BUDDY_READ_CACHE_PREVIEW`, id: `<fill after create>` | title `BUDDY_READ_CACHE_PROD`, id: `<fill after create>` | Versioned, authorized artifact summaries (1 h TTL) |
| `BUDDY_ARTIFACTS` | R2 bucket | `buddy-artifacts-preview` | `buddy-artifacts-prod` | Private artifact objects and diagnostic screenshots |
| `BROWSER` | Browser Run | declared | declared | Diagnostics only; unused while `BROWSER_DIAGNOSTICS_ENABLED=false` |
| `ASSETS` | Static assets | `cloudflare/buddy-edge/public` | same | Dashboard |

Queue consumer settings (both queues, both environments): `max_batch_size 10`, `max_batch_timeout 5`,
`max_retries 5`, `max_concurrency 2`, dead-letter queues as listed.

### KV namespace ids

`wrangler.jsonc` carries placeholder ids (`000…` for preview, `111…` for production). After running
`wrangler kv namespace create` (see the recipe), replace the placeholder in the matching `env.<name>.kv_namespaces`
entry and record the id in the table above. KV namespace ids are identifiers, not secrets.

## Secrets (never in this repository)

| Name | Where | Set with |
| --- | --- | --- |
| `EDGE_SERVICE_KEY` | Worker secret, per environment | `wrangler secret put EDGE_SERVICE_KEY --env preview` / `--env production` |
| Queues publishing token | Azure Key Vault (Azure side) | Cloudflare API token scoped to Queues write on the two producer queues of one environment |
| Deployment token | CI or operator | Cloudflare API token scoped to Workers, Durable Objects, Queues, KV, R2, Workflows for one account |

Use separate credentials for deployment, event publishing, artifact access and delegated Azure callbacks.
Do not reuse the existing Azure token that only has Workers AI permissions.

## Vars per environment

Identical in both environments today; all feature flags `"false"`:
`AZURE_API_BASE`, `ALLOWED_ORIGIN` (the environment's workers.dev origin), `SUPERVISION_ENABLED`,
`AUTO_RECOVERY_ENABLED`, `READ_CACHE_ENABLED`, `BROWSER_DIAGNOSTICS_ENABLED`, `DAILY_BUDGET_EVENTS=50000`,
`DAILY_BUDGET_CALLBACKS=500`, `DAILY_BUDGET_WORKFLOWS=200`, `DAILY_BUDGET_CAPTURES=10`,
`MAX_ACTIVE_SOCKETS_PER_TASK=20`.

## Coverage status

Workers, Durable Objects, Workflows, Queues, KV and R2 are within the published startup coverage list.
Browser Run coverage is unconfirmed for this account; the `BROWSER` binding is declared so the configuration is
complete, but the capture path stays disabled until account-specific billing evidence exists.
