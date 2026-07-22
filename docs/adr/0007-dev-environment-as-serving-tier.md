# ADR 0007: The dev environment is the serving tier

**Status:** Accepted — 2026-07-22

## Context

All first-party agents (~20 local Claude Code projects and onboarded remote machines)
consume `ca-buddy-api-dev` in `rg-buddy-dev`. No prod environment exists;
`prod.bicepparam` carries placeholders. The workload is single-operator and
low-concurrency. Promoting a prod environment now would roughly double cost for no
consumer-visible benefit.

## Decision

The dev environment is formally the serving tier until promotion criteria are met.
Hardening that follows from this decision:

- `minReplicas: 1` on the API app in every environment (scale-to-zero cold starts
  caused MCP timeouts). The live app already ran at 1; the IaC now matches.
- Alerts module (`modules/alerts.bicep`): email action group, API 5xx alert,
  per-app replica-restart alerts, and a monthly cost budget with actual and
  forecast notifications. Deployed standalone; a full `main.bicep` redeploy from the
  param files is forbidden while image tags are CI placeholders (see the warnings in
  `parameters/*.bicepparam`).
- Accepted risks, explicit: dev Postgres has 7-day non-geo backups; dev Redis is a
  single non-HA container (recovered by the outbox relay); the memory hub is a pinned
  single replica (restart = short memory-plane outage); no alert fires on
  "app fully down with zero traffic"; `RestartCount` alerts use Maximum aggregation
  and reset only when replicas are replaced.

## Promotion criteria (any of)

1. A second human operator or external consumer depends on Buddy.
2. Sustained load approaches dev SKU limits (queue age, p95 latency, Redis memory).
3. A data-loss or availability incident exceeds the accepted-risk posture above.

Promotion path: fill `prod.bicepparam` (real Qdrant cluster, image tags from CI),
deploy Phase 4 of the architecture plan, then supersede this ADR.

## Notes

The legacy local memory plane is retired: no `buddy_episodes` collection exists in
the local Qdrant (verified 2026-07-22), so there is no Phase 3 corpus to migrate.
The `legacy` memory backend remains in code for local development only; removing it
is a follow-up once local dev also targets a hub instance.
