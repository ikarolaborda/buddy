# Cloudflare edge deployment (buddy-edge)

Operator recipe for the Worker in `cloudflare/buddy-edge`. Every command below is meant to be run by a person
with the right Cloudflare account access. None of them are executed by the repository, CI, or an agent.
Resource names follow `infra/cloudflare/README.md`.

## 0. Prerequisites

- Node 22.12+ and `npm ci` inside `cloudflare/buddy-edge`.
- `npx wrangler login` (or `CLOUDFLARE_API_TOKEN` in the shell for a scoped deployment token).
- `npm run typecheck && npm test` pass locally.
- The Azure side is deployed with the `/api/internal/cloudflare/*` endpoints and the `X-Buddy-Edge-Key` value
  stored in Key Vault. Event publication on Azure stays off until step 4.

## 1. Create preview resources (not executed here)

```bash
cd cloudflare/buddy-edge

# Queues and their dead-letter queues
npx wrangler queues create buddy-events-preview
npx wrangler queues create buddy-events-dlq-preview
npx wrangler queues create buddy-artifacts-preview
npx wrangler queues create buddy-artifacts-dlq-preview

# R2 bucket for private artifacts and screenshots
npx wrangler r2 bucket create buddy-artifacts-preview

# KV namespace: copy the printed id into env.preview.kv_namespaces in wrangler.jsonc
npx wrangler kv namespace create BUDDY_READ_CACHE --env preview
```

Durable Object namespaces and the Workflow are created by the first deploy from the `migrations` and
`workflows` sections of `wrangler.jsonc`; there is nothing to create manually for them.

Record the KV id in `infra/cloudflare/README.md`. Do not record tokens anywhere.

## 2. Set the secret

```bash
npx wrangler secret put EDGE_SERVICE_KEY --env preview
```

Paste the value that Azure expects in `X-Buddy-Edge-Key` for the preview environment. Use a different value for
production. The secret is never written to `wrangler.jsonc` or committed.

## 3. Deploy preview

```bash
npm run deploy:dry-run          # verifies the bundle and bindings without uploading
npm run deploy:preview          # wrangler deploy --env preview
```

Set `ALLOWED_ORIGIN` in `env.preview.vars` to the printed workers.dev origin before the first real browser test,
then redeploy.

## 4. Verify preview

1. `curl -sS https://buddy-edge-preview.<subdomain>.workers.dev/` returns the dashboard with the CSP,
   `Referrer-Policy: no-referrer` and `X-Content-Type-Options: nosniff` headers.
2. `curl -sS -H "X-Buddy-Edge-Key: <key>" https://buddy-edge-preview.<subdomain>.workers.dev/internal/budget`
   returns zeroed counters (the key check and the budget Durable Object work).
3. Ask Azure for a view ticket for one owned pilot task (`POST /api/buddy/tasks/{task}/view-tickets`) and open
   `https://buddy-edge-preview.<subdomain>.workers.dev/#ticket=<ticket>`. The page must exchange the ticket,
   clear the fragment, show the snapshot and connect the WebSocket ("Live (WebSocket)").
4. Enable event publication on Azure for the pilot client only. Watch `wrangler tail --env preview` for the
   consumer and confirm the dashboard receives events in order; compare `progress_sequence` with PostgreSQL.
5. `npx wrangler queues consumer` output and the Cloudflare dashboard must show zero dead letters after the
   pilot run. If not, follow the DLQ procedure below before enabling anything else.
6. Only then, and one at a time: `SUPERVISION_ENABLED=true` (observe diagnoses, no recoveries), later
   `AUTO_RECOVERY_ENABLED=true` after the recovery gate passes, then `READ_CACHE_ENABLED=true` after correctness
   and hit-rate measurements. `BROWSER_DIAGNOSTICS_ENABLED` stays `false` (see cost notes).

## 5. Deploy production

Repeat step 1 with the `-prod` names and `--env production`, step 2 with `--env production`, then:

```bash
npm run deploy:prod             # wrangler deploy --env production
```

Keep all flags `false` on the first production deploy. Record the deployment version id printed by wrangler
together with the source SHA in the handoff.

## 6. Rollback

Two independent levers; use the cheapest that stops the problem.

1. Feature flags: set the affected var back to `"false"` in `wrangler.jsonc` and redeploy. Order matters:
   stop new supervisor actions (`AUTO_RECOVERY_ENABLED`, then `SUPERVISION_ENABLED`) before disabling event
   consumers on the Azure side. Disabled flags do not delete storage; queues, R2 objects and Durable Object
   state remain and keep costing until cleaned up deliberately.
2. Code rollback:

```bash
npx wrangler versions list --env preview        # find the last good version id
npx wrangler rollback <version-id> --env preview
```

Do the same with `--env production`. Do not delete buckets, queues or namespaces as a rollback shortcut.

Draining Workflow instances after a rollback: `npx wrangler workflows instances list buddy-task-supervisor-preview`
and terminate only supervisor instances (`npx wrangler workflows instances terminate <name> <id>`); they never
own model work, so terminating them cannot cancel an evaluation.

## 7. Dead-letter queue replay

Messages land in `buddy-events-dlq-<env>` / `buddy-artifacts-dlq-<env>` after five failed deliveries. Typical
causes are a malformed envelope, an unsupported `schema_version`, or a projection outage.

1. Inspect without consuming: Cloudflare dashboard, Queues, the DLQ, "Messages", or
   `npx wrangler queues pull-consumer` is not used here; use the dashboard preview so nothing is acked.
2. Decide per message: fix the publisher (Azure) for malformed envelopes; for outages the envelopes are valid.
3. Replay valid envelopes by re-sending them to the primary queue with the Queues REST API
   (`POST /accounts/<account>/queues/<queue-id>/messages`, body `{"body": <envelope>}`), using the same event
   ids. The consumer is idempotent: duplicates are reported as `duplicate` by the projection and acknowledged;
   nothing creates new inference because a delivery repeated. Do a dry run first by listing the envelopes and
   their `task_id`/`task_sequence` and record who replayed what and when.
4. After replay, call `GET /tasks/{task}` for an affected task with a valid session (or watch the dashboard) to
   confirm the projection has no gap, and purge the replayed messages from the DLQ.

## 8. Cost and coverage notes

- Workers, Durable Objects, Workflows, Queues, KV and R2 are on Cloudflare's published startup coverage list;
  the account-specific grant still needs current billing evidence and the pilot limits from the plan apply
  ($25 total, $2 per day for new features), enforced approximately by `BuddyEdgeBudget` counters.
- Browser Run coverage is unconfirmed. The binding is declared and the code path is complete and tested with a
  mock runner, but `BROWSER_DIAGNOSTICS_ENABLED` must stay `false` until account-specific coverage evidence or
  an explicit decision to pay separately exists. Do not contact Cloudflare on the user's behalf without
  authorization.
- Storage keeps costing after flags are turned off. Cleanup (R2 lifecycle, DLQ purge, idle namespace removal)
  is a separate, explicit step and must be recorded before the credit expiry date in the plan.

## 9. Handoff

After every deploy record: source SHA, wrangler version id per environment, flag values, KV ids, tests run,
open reconciliation items from `GET /internal/budget`, and the rollback position.
