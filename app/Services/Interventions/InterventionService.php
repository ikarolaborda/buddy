<?php

namespace App\Services\Interventions;

use App\Enums\ApiScope;
use App\Enums\ErrorClass;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\ApiClient;
use App\Models\ApiKey;
use App\Models\BuddyIntervention;
use App\Models\BuddyTask;
use App\Services\Edge\TaskProgressService;
use App\Services\OutboxPublisher;
use App\Services\TaskStateService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InterventionService
{
    public function __construct(
        private InterventionContext $context,
        private ServiceDiagnostics $diagnostics,
        private OutboxPublisher $outbox,
        private TaskStateService $state,
        private TaskProgressService $progress,
    ) {}

    public function perform(BuddyTask $task, ApiClient $client, ApiKey $key, array $input): array
    {
        abort_unless($key->api_client_id === $client->id && $key->hasScope(ApiScope::InterventionsExecute), 403);
        abort_unless($task->api_client_id !== null && ($task->api_client_id === $client->id || $key->hasScope(ApiScope::Admin)), 404);

        return $this->execute($task, $client, $input, $key->hasScope(ApiScope::Admin), 'api_key');
    }

    /*
     * Delegated path for the Cloudflare supervisor (plan §8). The caller has
     * already verified a delegation bound to this task, generation and the
     * owning client's current interventions:execute scope; admin bypass is
     * never available through a delegation. Everything else is the same
     * bounded operation, so the audit trail records the principal.
     */
    public function performDelegated(BuddyTask $task, ApiClient $client, array $input, string $delegationId): array
    {
        abort_unless($task->api_client_id !== null && $task->api_client_id === $client->id, 404);

        return $this->execute($task, $client, $input, false, 'delegation:'.$delegationId);
    }

    private function execute(BuddyTask $task, ApiClient $client, array $input, bool $admin, string $principal): array
    {
        if (! config('buddy.interventions.enabled')) {
            throw ValidationException::withMessages(['action' => 'Interventions are disabled.']);
        }

        $args = Validator::validate($input, [
            'request_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'action' => ['required', Rule::in(['diagnose_health', 'recover_evaluation'])],
            'blocker' => ['required', Rule::in(['operational_failure', 'capability_missing', 'policy_denied', 'approval_required', 'credential_restriction'])],
            'context' => ['required', 'array:summary,session_id,last_error,attempted_actions'],
            'context.summary' => ['required', 'string', 'max:4000'],
            'context.session_id' => ['sometimes', 'string', 'max:255'],
            'context.last_error' => ['sometimes', 'string', 'max:2000'],
            'context.attempted_actions' => ['sometimes', 'array', 'max:10'],
            'context.attempted_actions.*' => ['string', 'max:500'],
        ]);
        $hash = hash_hmac('sha256', (string) json_encode($this->canonical($args)), (string) config('app.key'));

        [$intervention, $created] = DB::transaction(function () use ($task, $client, $admin, $principal, $args, $hash) {
            $task = BuddyTask::query()->lockForUpdate()->findOrFail($task->id);
            abort_unless($task->api_client_id !== null && ($task->api_client_id === $client->id || $admin), 404);
            $existing = BuddyIntervention::where('buddy_task_id', $task->id)->where('request_id', $args['request_id'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Request ID already used with a different payload.']);
                }

                return [$existing, false];
            }

            $intervention = BuddyIntervention::create([
                'buddy_task_id' => $task->id,
                'api_client_id' => $client->id,
                'request_id' => $args['request_id'],
                'request_hash' => $hash,
                'action' => $args['action'],
                'status' => 'running',
                'context' => $this->context->capture($task, $args['context']) + ['blocker' => $args['blocker'], 'principal' => $principal],
            ]);

            if ($args['action'] === 'recover_evaluation') {
                $this->recover($task, $intervention, $args['blocker']);
            }

            return [$intervention, true];
        });

        if ($created && $args['action'] === 'diagnose_health') {
            try {
                $intervention->update(['status' => 'completed', 'result' => $this->diagnostics->inspect()]);
            } catch (\Throwable) {
                $intervention->update(['status' => 'failed', 'result' => ['message' => 'Diagnostics failed; use the intervention ID for operator review.']]);
            }
        }

        return $intervention->response();
    }

    private function recover(BuddyTask $task, BuddyIntervention $intervention, string $blocker): void
    {
        $run = $task->runs()->orderByDesc('run_number')->first();
        $existing = BuddyTask::where('recovery_of_task_id', $task->id)->first();
        if ($existing !== null) {
            $intervention->update(['status' => 'dispatched', 'result' => $this->recoveryResult($existing)]);

            return;
        }

        $transient = $run?->error_category === ErrorClass::Transient->value
            || ($run?->error_category === null && in_array($run?->error_class, [
                ConnectionException::class,
                TimeoutExceededException::class,
            ], true));

        if ($blocker !== 'operational_failure' || $task->status !== TaskStatus::Failed
            || $task->operation !== 'evaluate' || $task->recovery_of_task_id !== null
            || $run?->run_type !== 'evaluation' || $run?->status !== RunStatus::Failed || ! $transient) {
            $intervention->update(['status' => 'blocked', 'result' => [
                'message' => 'Recovery requires an original failed evaluation with a recorded transient operational error. Policy, approval, credential restrictions, council reruns, and recovery chains require operator review.',
            ]]);

            return;
        }

        $recovery = BuddyTask::create([
            'api_client_id' => $task->api_client_id,
            'source_agent' => $task->source_agent,
            'repo' => $task->repo,
            'branch' => $task->branch,
            'task_summary' => $task->task_summary,
            'problem_type' => $task->problem_type,
            'operation' => 'evaluate',
            'constraints' => $task->constraints,
            'evidence' => $task->evidence,
            'requested_outcome' => $task->requested_outcome,
            'knowledge_context' => $task->knowledge_context,
            'knowledge_context_status' => $task->knowledge_context_status,
            'knowledge_context_hash' => $task->knowledge_context_hash,
            'knowledge_context_fetched_at' => $task->knowledge_context_fetched_at,
            'status' => TaskStatus::Pending,
            'recovery_of_task_id' => $task->id,
        ]);
        foreach ($task->artifacts()->where('type', '!=', 'council_transcript')->get() as $artifact) {
            $recovery->artifacts()->create($artifact->only(['type', 'content', 'metadata']));
        }
        $recovery->artifacts()->create([
            'type' => 'other',
            'content' => (string) json_encode($intervention->context),
            'metadata' => ['kind' => 'intervention_context', 'intervention_id' => $intervention->id, 'trust' => 'caller_testimony'],
        ]);

        $this->state->transition($recovery, TaskStatus::Evaluating);
        $this->outbox->appendTaskSubmitted($recovery);
        $intervention->update(['status' => 'dispatched', 'result' => $this->recoveryResult($recovery)]);
        $this->progress->record($task, TaskProgressService::TYPE_RECOVERY, [
            'recovery_task_id' => $recovery->ulid,
            'intervention_id' => $intervention->id,
        ]);
    }

    private function recoveryResult(BuddyTask $task): array
    {
        return [
            'recovery_task_id' => $task->ulid,
            'message' => 'Recovery queued through the outbox. Poll buddy.get_task_status for the recovery task; dispatched does not mean resolved.',
        ];
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
