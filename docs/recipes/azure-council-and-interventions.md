# Azure council and operational interventions

Azure production uses `BUDDY_COUNCIL_PROFILE=azure` as its default council.
Supply `profile: "azure"` to `buddy.council_evaluate` or
`POST /api/buddy/tasks/{task_id}/council` to select it explicitly on other
deployments. OpenRouter remains an available profile when its key is configured.
Selection is explicit; a provider refusal does not cause a provider switch.

The Azure roster uses the models allowed by the Aerolambda subscription:

| Seat | Azure deployment | Reasoning | Review focus |
| --- | --- | --- | --- |
| Chairman | `gpt-6-astra` | `xhigh` | Frame hypotheses and narrate the computed verdict |
| Correctness | `gpt-5.5` | `high` | Invariants, counterexamples, tests |
| Reliability | `gpt-5.5` | `high` | Timeouts, concurrency, recovery |
| Security | `gpt-5.5` | `high` | Authorization, privacy, trust boundaries |

The three reviewers share a model and family. Their separate calls and review
focuses do not establish model diversity. Every verdict records the actual roster,
distinct model count, family spread, absent reviewers, and silent reviewers.
All three reviewers must provide a position. The chairman keeps `xhigh` on JSON repair.

Set `AZURE_OPENAI_URL` to the resource root, such as
`https://resource.openai.azure.com`, and configure `AZURE_OPENAI_API_KEY` through
the deployment's secret store (the ignored `.env` for local development).
Optional `BUDDY_COUNCIL_AZURE_CHAIRMAN` and `BUDDY_COUNCIL_AZURE_REVIEWER` override
deployment names. The client uses Azure's `/openai/v1/responses`, `api-key`,
and `max_output_tokens`. OpenRouter credentials and headers stay with OpenRouter.

Azure requests use [background mode](https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/responses#background-tasks).
A synchronous Astra `xhigh` council request was reset after about 248 seconds
during the production canary. Background mode starts one generation and polls
its response ID, so a dropped polling connection does not restart inference.
Reviewers start in parallel. Background mode requires `store=true`; the client
deletes the response after retrieval and attempts cancellation and deletion on
timeout. Cleanup failures are logged. Astra keeps `xhigh` throughout.
Deletion is attempted even when cancellation throws. Each queued council carries
a persisted execution owner; failure callbacks and late verdicts cannot change a
newer owner's task or runs. Daily-cap failures terminate the claimed task.

Allocate enough Azure quota for parallel reviewers and their output budgets.
The production GPT-5.5 deployment has 200,000 tokens per minute; its earlier
10,000-token allocation was below the council's per-request output budget.

Run `php artisan migrate`, then restart application and queue processes.
`php artisan buddy:council-probe azure` makes one live JSON request per seat,
reports model and usage, and exits unsuccessfully if any seat fails. It incurs
model usage. A successful probe verifies connectivity and payload compatibility;
it is not a deliberation quality benchmark.

The council timeout is 1,800 seconds. Start the worker with `--timeout=1860`
and keep queue `retry_after` at 2,400 seconds. A `queue:listen` process also needs
this timeout; its parent can kill a council before the job's own timeout.
Redis and database queues default to `BUDDY_QUEUE_RETRY_AFTER`; their optional
`REDIS_QUEUE_RETRY_AFTER` and `DB_QUEUE_RETRY_AFTER` overrides must also exceed
the worker timeout. Health diagnostics inspect the selected queue's value.

## Interventions

`buddy.intervene` and `POST /api/buddy/tasks/{task_id}/interventions` share the
same implementation. Both require an authenticated key with
`interventions:execute` and access to the task. Use a key that also has
`tasks:read` to poll the result. Unowned legacy tasks are not intervention targets.
Issue this scope only to the intended client with the existing API-key tooling.

```json
{
  "task_id": "TASK_ULID",
  "request_id": "session-42-health-1",
  "action": "diagnose_health",
  "blocker": "operational_failure",
  "context": {
    "session_id": "session-42",
    "summary": "The evaluation exhausted its connection retries.",
    "last_error": "Connection timed out",
    "attempted_actions": ["Polled the task and checked the worker"]
  }
}
```

Send visible task context, not private reasoning, credentials, cookies, or a
full session dump. Buddy stores a bounded snapshot of the task, supplied context,
evidence, and recent artifacts. Common secret patterns are redacted as a secondary
measure; callers must omit secrets. Context is treated as caller testimony.

- `diagnose_health` checks Buddy's database, queue, memory backend, timeout
  configuration, and whether council providers are configured. It does not
  call models, fetch caller-supplied URLs, expose dependency responses, or inspect
  the actual worker process. A missing dependency produces `health: "degraded"`.
- `recover_evaluation` accepts only an original failed evaluation with a recorded
  transient error. Older runs qualify only when they record a known connection
  or queue-timeout exception. It preserves the failed task and creates one linked
  task with the same evidence, constraints, artifacts, and a redacted intervention
  context artifact. The transactional outbox dispatches the existing evaluator.

Reuse `request_id` with the identical payload to retrieve the same audit result.
Changing the payload under that ID is rejected. A second request ID still cannot
create a second recovery of the same task. Recovery tasks cannot produce recovery
chains. Normal queue retry limits still apply to the linked task.

Recovery returns `status: "dispatched"` and `result.recovery_task_id`; this is
queue acceptance, not proof of success. Poll `buddy.get_task_status` for the
linked task, then close it with an outcome and notes. The original failed task
and intervention audit remain available. Task status includes the three latest
intervention records.

Policy denials, missing approvals, credential restrictions, permanent errors,
live tasks, and council reruns do not qualify for automatic recovery. The service
has no arbitrary shell, browser credential injection, or caller-defined executor.
Extend it only with separately reviewed operations and their own authorization.

Set `BUDDY_INTERVENTIONS=false` and restart the API to disable new intervention
requests. Schema changes are additive; retain them when rolling images back.
The outbox and worker use at-least-once delivery with task claims. The one-recovery
constraint prevents duplicate logical tasks; it does not promise exactly-once
provider billing after an ambiguous network failure. Existing API rate limits apply;
this feature does not introduce a tenant-wide concurrency quota.

Cloudflare Fable 5.1 is a third-party AI Gateway model. As verified on September
15, 2026, an earlier account check found a zero AI Gateway prepaid balance, and Cloudflare's
[startup-credit terms](https://www.cloudflare.com/startups/) excluded AI Gateway.
It is not enabled by this change. Workers AI's existing profile remains available
under its own billing conditions. The Azure credential was repaired on September 15
using an account-scoped Workers AI token; both services verified it successfully.
The earlier token allowed only the local IP address. The startup grant had
$9,999.65 remaining and expires on August 11, 2027. Cloudflare authentication
does not establish model suitability: the subsequent full GLM-5.3 probe timed out.

## Strict chairman output (Azure)

`BUDDY_COUNCIL_AZURE_STRICT_CHAIR=true` makes the Azure chairman's frame and
verdict calls strict `json_schema` requests (ADR 0009 amendment 2026-09-15);
members and other profiles are unaffected. The worker reads the switch at
container start (configuration is cached there), so changing it means a new
worker revision: `az containerapp update -n ca-buddy-worker-credit
--set-env-vars BUDDY_COUNCIL_AZURE_STRICT_CHAIR=false` is the rollback and
needs no image. A council already running keeps its old replica for up to the
600 s grace period and finishes on the old setting. Proof of the mode in
production: `ContainerAppConsoleLogs_CL | where Log_s contains "Council chair
request"` shows `phase` and `format` per chair call.

## Sol in the Workers AI council

Set `BUDDY_COUNCIL_WORKERS_AI_SOL=true` and configure `OPENROUTER_API_KEY`
through the deployment's secret store to replace the GLM seat with
`openai/gpt-5.6-sol` at `xhigh`. Restart the API and worker after changing settings.
Select `profile: "workers_ai"` to use this council; Azure remains the production
default. The other four reviewers and the chairman continue on Workers AI.

This switch selects Sol before the first request. It never tries GLM-5.3 first
and does not fall back to GLM when Sol fails. A refusal stays a refusal. The
whole council is rejected before inference if a required provider is not
configured. Serial calls, parallel rounds, and JSON repair use each seat's own
provider endpoint, credential, headers, and payload. The actual model, family,
reasoning effort, and provider override appear in the verdict roster.

Sol uses the OpenRouter balance, separate from Cloudflare startup credits.
As verified on September 15, 2026, the Azure subscription's allowed-model list
contains Astra, GPT-5.5, and the embedding model; Sol is not an allowed Azure
deployment. Keep the switch false when restricting use to startup credits.
With the switch false, the measured GLM-5.2 seat remains. GLM-5.3 is excluded
in both configurations after repeated Cloudflare timeouts, including a 235-second
HTTP 408 from the Azure worker on September 15.

[OpenAI's Sol model documentation](https://developers.openai.com/api/docs/models/gpt-5.6-sol)
confirms support for `xhigh`, JSON output, and the Chat Completions endpoint.
Provider catalog availability is not proof that an account has enough balance.
Run deployment probes only from the Azure Buddy worker. A small probe checks
connectivity; use a full falsification packet to assess council suitability.
