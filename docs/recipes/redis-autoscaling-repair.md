# Redis autoscaling repair (P0)

Evidence, procedure and rollback for the worker autoscaler. Decision record:
[ADR 0012](../adr/0012-worker-autoscaling-signal.md).

## Evidence collected on 2026-09-15

| Check | Command or source | Result |
| --- | --- | --- |
| Scale rule in place | `az containerapp show -n ca-buddy-worker-credit --query properties.template.scale` | `redis`, `address: ca-redis-credit:6379`, `listName: buddy:queue:default`, TLS off, 1–4 replicas |
| Real queue key | `php artisan tinker` inside the worker: prefix, queue, db | `laravel-database-` + `queues:default`, db 0 |
| Worker reaches Redis | same session, `LLEN` and TCP probe | `llen 0`, connect OK to 100.100.226.25:6379 |
| Scaler reaches Redis | Log Analytics `ContainerAppSystemLogs_CL`, `Reason_s == 'KEDAScalerFailed'` | 1,469 events / 14 days, every revision, `connection refused` |
| FQDN alternative | probe app `ca-keda-probe-credit` with the internal FQDN | 15 failures, `dial tcp 100.100.0.209:6379: i/o timeout` |
| Public HTTPS egress | probe app `ca-keda-probe2-credit`, `metrics-api` rule against api.github.com | HTTP 403 returned by GitHub (request left the cluster and came back); scaler rebuilt each poll |
| Replica history | `az monitor metrics list --metric Replicas` (7 days, hourly max) | 1.0 in every sample |

Useful query:

```kusto
ContainerAppSystemLogs_CL
| where TimeGenerated > ago(14d)
| where ContainerAppName_s == 'ca-buddy-worker-credit'
| where Reason_s has 'KEDA'
| summarize n=count(), t0=min(TimeGenerated), t1=max(TimeGenerated) by Reason_s, RevisionName_s
| order by t1 desc
```

Read the system log stream with `--format json`; the CLI rejects `--format text`.
`az containerapp exec` needs a TTY (`script -q /dev/null az containerapp exec ...`)
and mangles backslashes and long payloads; keep tinker one-liners short, single
quoted inside, without `$` variables.

## Release procedure

1. Deploy the image that contains `GET /api/internal/scaling/queue-depth`
   (this change) with `BUDDY_SCALING_METRICS_KEY` set on the **API** from Key
   Vault secret `buddy-scaling-metrics-key`. Confirm
   `curl -H "X-Buddy-Scaling-Key: ..." https://buddy.aerolambda.tech/api/internal/scaling/queue-depth`
   returns `{"queue":"default","pending":0,...}` and that a request without the
   header returns 401.
2. Switch the worker rule. Either deploy `infra/azure/main.bicep` with
   `workerScaleRuleType=metrics-api` and
   `scalingMetricsUrl=https://buddy.aerolambda.tech/api/internal/scaling/queue-depth`,
   or export the worker (`az containerapp show -o yaml > worker.yaml`), replace the
   single scale rule with the `queue-depth-api` rule from
   `modules/buddy-worker.bicep`, add the `scaling-metrics-key` Key Vault secret
   reference, and apply with `az containerapp update --yaml worker.yaml`. The
   YAML round trip preserves every `secretRef`; inspect the diff before applying.
   Do this while `reserved` and `pending` are both 0 so the rolling replica
   replacement cannot interrupt a council.
3. Watch `KEDAScalerFailed` stop for at least five polling intervals (150 s).
4. Run the bounded experiment from an API replica or a one-off job execution:
   `php artisan buddy:queue:synthetic --count=30 --seconds=90 --confirm`.
   Record backlog (`pending`), desired and ready replicas
   (`az containerapp replica list`), drain time, scaler logs, and
   `buddy_runs` count before and after (must not change). Expect at least two
   ready replicas within about 120 s of the metric becoming visible and scale-in
   after the 300 s cooldown with one replica remaining.
5. Record the results in the handoff and close G1.

## Rollback

- Re-apply the exported worker YAML from before the change (the pre-change
  export is kept outside the repository), or redeploy Bicep with
  `workerScaleRuleType=redis`. The worker keeps consuming the queue in both
  states; only the scale signal changes.
- Leave the API endpoint in place; it is inert without the header key and
  returns 404 without a configured key.

## Release record (2026-09-15)

- API revision `ca-buddy-api-credit--edge-e29d9f0` serves the endpoint; verified
  200 with the key and 401 without.
- Worker revision `ca-buddy-worker-credit--edge-e29d9f0` carries the single
  `queue-depth-api` rule (`metrics-api`, `targetValue 10`, auth
  `scaling-metrics-key`); applied through the YAML round trip with a fresh
  `revisionSuffix` (the first attempt failed only because the export still
  carried the old suffix).
- Synthetic backlog dispatched at 16:05:29 UTC through a one-off execution of
  `caj-buddy-outbox-credit` using a start template file (`az containerapp job
  start --yaml`), because `--args` cannot carry tokens that begin with `--`.
  `pending` went 0 to 30 on `laravel-database-queues:default`.
- Scale-out observed: 16:06:29 three replicas provisioned, 16:07:02 three
  running with `reserved 3`, `pending 27`. Drain and scale-in are recorded in
  the evidence manifest.
- Both probe apps were deleted after the evidence above was captured; recreate
  one with `az containerapp create --min-replicas 0 --max-replicas 1` if a new
  scaler address needs testing without touching the worker.
