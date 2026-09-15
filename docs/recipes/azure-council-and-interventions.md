# Azure council and operational interventions

The default council remains OpenRouter. Supply `profile: "azure"` to
`buddy.council_evaluate` or `POST /api/buddy/tasks/{task_id}/council` to select
the Azure backup for that task. `BUDDY_COUNCIL_PROFILE=azure` makes Azure the
default. Selection is explicit; a provider refusal does not cause a provider switch.

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
deployment names. The client uses Azure's `/openai/v1/chat/completions`, `api-key`,
and `max_completion_tokens`. OpenRouter credentials and headers stay with OpenRouter.

Run `php artisan migrate`, then restart application and queue processes.
`php artisan buddy:council-probe azure` makes one live JSON request per seat,
reports model and usage, and exits unsuccessfully if any seat fails. It incurs
model usage. A successful probe verifies connectivity and payload compatibility;
it is not a deliberation quality benchmark.

The council timeout is 1,800 seconds. Start the worker with `--timeout=1860`
and keep queue `retry_after` at 2,400 seconds. A `queue:listen` process also needs
this timeout; its parent can kill a council before the job's own timeout.

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
15, 2026, the account's AI Gateway prepaid balance was zero, and Cloudflare's
[startup-credit terms](https://www.cloudflare.com/startups/) excluded AI Gateway.
It is not enabled by this change. Workers AI's existing profile remains available
under its own billing conditions.
