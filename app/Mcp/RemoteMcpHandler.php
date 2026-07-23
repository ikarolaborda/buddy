<?php

namespace App\Mcp;

use App\DTOs\ProblemPacket;
use App\Enums\ApiScope;
use App\Enums\TaskOutcome;
use App\Enums\TaskStatus;
use App\Models\ApiClient;
use App\Models\ApiKey;
use App\Models\BuddyTask;
use App\Services\Council\CouncilGate;
use App\Services\EvaluatorOptimizerService;
use App\Services\OutboxPublisher;
use App\Services\TaskStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/*
 * Native Streamable HTTP MCP surface (stateless). Runs in-process against
 * the same services as the REST controllers, with the authenticated
 * client attributed to every task and the same ownership fence: a
 * non-owner sees "task not found", never another client's data. This is
 * the zero-install path — remote agents need only a URL and an API key.
 */
class RemoteMcpHandler
{
    protected const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        protected EvaluatorOptimizerService $evaluator,
        protected OutboxPublisher $outbox,
        protected TaskStateService $state,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null means "202, no body"
     */
    public function handle(array $message, ApiClient $client, ApiKey $key): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? '';

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => $this->negotiate($message['params']['protocolVersion'] ?? null),
                'capabilities' => ['tools' => new \stdClass],
                'serverInfo' => ['name' => 'buddy', 'version' => '2.0.0'],
                'instructions' => UsageInstructions::forInitialize(),
            ]),
            'ping' => $this->result($id, new \stdClass),
            'prompts/list' => $this->result($id, ['prompts' => []]),
            'resources/list' => $this->result($id, ['resources' => []]),
            'tools/list' => $this->result($id, ['tools' => RemoteToolDefinitions::all()]),
            'tools/call' => $this->call($id, $message['params'] ?? [], $client, $key),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    protected function negotiate(?string $requested): string
    {
        return in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requested
            : self::SUPPORTED_PROTOCOL_VERSIONS[0];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function call(mixed $id, array $params, ApiClient $client, ApiKey $key): array
    {
        $tool = (string) ($params['name'] ?? '');
        $args = $params['arguments'] ?? [];

        $requiredScope = $tool === 'buddy.get_task_status' ? ApiScope::TasksRead : ApiScope::TasksWrite;

        if (! $key->hasScope($requiredScope)) {
            return $this->toolError($id, "Insufficient scope: {$requiredScope->value} required.");
        }

        try {
            return match ($tool) {
                'buddy.submit_problem' => $this->submitProblem($id, $args, $client),
                'buddy.get_task_status' => $this->getTaskStatus($id, $args, $client, $key),
                'buddy.evaluate_task' => $this->evaluateTask($id, $args, $client, $key),
                'buddy.council_evaluate' => $this->councilEvaluate($id, $args, $client, $key),
                'buddy.refine_prompt' => $this->refinePrompt($id, $args, $client, $key),
                'buddy.attach_artifact' => $this->attachArtifact($id, $args, $client, $key),
                'buddy.close_task' => $this->closeTask($id, $args, $client, $key),
                default => $this->toolError($id, "Unknown tool: {$tool}"),
            };
        } catch (ValidationException $e) {
            return $this->toolError($id, 'Validation failed: '.implode(' ', $e->validator->errors()->all()));
        } catch (\Throwable $e) {
            report($e);

            return $this->toolError($id, 'Tool execution failed.');
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function submitProblem(mixed $id, array $args, ApiClient $client): array
    {
        $validated = Validator::validate($args, [
            'source_agent' => ['required', 'string', 'max:255'],
            'task_summary' => ['required', 'string'],
            'problem_type' => ['required', 'string'],
            'repo' => ['sometimes', 'nullable', 'string'],
            'branch' => ['sometimes', 'nullable', 'string'],
            'constraints' => ['sometimes', 'array'],
            'evidence' => ['sometimes', 'array'],
            'requested_outcome' => ['sometimes', 'nullable', 'string'],
        ]);

        $task = DB::transaction(fn () => $this->evaluator->createTask(
            ProblemPacket::fromArray($validated),
            $client->id,
        ));

        return $this->toolResult($id, [
            'task_id' => $task->ulid,
            'status' => $task->status->value,
            'close_protocol' => UsageInstructions::CLOSE_PROTOCOL,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function getTaskStatus(mixed $id, array $args, ApiClient $client, ApiKey $key): array
    {
        $task = $this->ownedTask($args, $client, $key);

        if ($task === null) {
            return $this->toolError($id, 'Task not found.');
        }

        $recommendation = $task->latestRecommendation();

        return $this->toolResult($id, [
            'task_id' => $task->ulid,
            'status' => $task->status->value,
            'runs' => $task->runs()->count(),
            'recommendation' => $recommendation === null ? null : [
                'accepted' => $recommendation->accepted,
                'confidence' => $recommendation->confidence->value,
                'summary' => $recommendation->summary,
                'recommended_plan' => $recommendation->recommended_plan,
                'rejected_reasons' => $recommendation->rejected_reasons,
                'required_followups' => $recommendation->required_followups,
                'risks' => $recommendation->risks,
                'next_actions' => $recommendation->next_actions,
                'memory_hits' => $recommendation->memory_hits,
            ],
            'council_eligible' => app(CouncilGate::class)
                ->evaluate($task, null, null)['allowed'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function evaluateTask(mixed $id, array $args, ApiClient $client, ApiKey $key): array
    {
        $task = $this->ownedTask($args, $client, $key);

        if ($task === null) {
            return $this->toolError($id, 'Task not found.');
        }

        if ($task->isTerminal()) {
            return $this->toolError($id, 'Task is in a terminal state.');
        }

        DB::transaction(function () use ($task) {
            if ($task->status === TaskStatus::Pending) {
                $this->state->transition($task, TaskStatus::Evaluating);
            }

            $this->outbox->appendTaskSubmitted($task);
        });

        return $this->toolResult($id, [
            'task_id' => $task->ulid,
            'status' => 'evaluating',
            'message' => 'Evaluation dispatched. Poll buddy.get_task_status.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function refinePrompt(mixed $id, array $args, ApiClient $client, ApiKey $key): array
    {
        $task = $this->ownedTask($args, $client, $key);

        if ($task === null) {
            return $this->toolError($id, 'Task not found.');
        }

        if ($task->isTerminal()) {
            return $this->toolError($id, 'Task is in a terminal state.');
        }

        $result = $this->evaluator->refine($task);

        return $this->toolResult($id, $result->toArray());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function attachArtifact(mixed $id, array $args, ApiClient $client, ApiKey $key): array
    {
        $task = $this->ownedTask($args, $client, $key);

        if ($task === null) {
            return $this->toolError($id, 'Task not found.');
        }

        if ($task->isTerminal()) {
            return $this->toolError($id, 'Task is in a terminal state.');
        }

        $validated = Validator::validate($args, [
            'task_id' => ['required', 'string'],
            'type' => ['required', 'string'],
            'content' => ['required', 'string'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $artifact = $task->artifacts()->create([
            'type' => $validated['type'],
            'content' => $validated['content'],
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return $this->toolResult($id, ['artifact_id' => $artifact->id]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    /**
     * One council = one task: an already-evaluated (terminal) task
     * cannot be re-deliberated; submit a fresh task with the evidence.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function councilEvaluate(mixed $id, array $args, ApiClient $client, ApiKey $key): array
    {
        if (! config('buddy_agents.council.enabled')) {
            return $this->toolError($id, 'Council is disabled.');
        }

        $task = $this->ownedTask($args, $client, $key);

        if ($task === null) {
            return $this->toolError($id, 'Task not found.');
        }

        if ($task->isTerminal()) {
            return $this->toolError($id, 'Task is terminal; submit a new task for council deliberation.');
        }

        $gate = app(CouncilGate::class)->evaluate(
            $task,
            isset($args['criticality']) ? (string) $args['criticality'] : null,
            isset($args['reason']) ? (string) $args['reason'] : null,
        );

        if (! $gate['allowed']) {
            return $this->toolError($id, $gate['message']);
        }

        Log::info('Council gate passed', [
            'task_ulid' => $task->ulid,
            'basis' => $gate['basis'],
            'markers' => $gate['markers'],
            'reason' => isset($args['reason']) ? (string) $args['reason'] : null,
        ]);

        DB::transaction(function () use ($task) {
            $task->operation = 'council';
            $task->save();

            if ($task->status === TaskStatus::Pending) {
                $this->state->transition($task, TaskStatus::Evaluating);
            }

            $this->outbox->appendTaskSubmitted($task);
        });

        return $this->toolResult($id, [
            'task_id' => $task->ulid,
            'status' => 'deliberating',
            'message' => 'Council convened (5 models, falsification rounds). Expect 2-10 minutes; poll buddy.get_task_status.',
        ]);
    }

    protected function closeTask(mixed $id, array $args, ApiClient $client, ApiKey $key): array
    {
        $task = $this->ownedTask($args, $client, $key);

        if ($task === null) {
            return $this->toolError($id, 'Task not found.');
        }

        if ($task->status->isTerminal() && $task->status !== TaskStatus::Completed) {
            return $this->toolError($id, 'Task is already in a terminal state.');
        }

        // inputSchema enums are advisory; unknown outcomes degrade to null
        // rather than failing the close.
        $outcome = TaskOutcome::tryFrom((string) ($args['outcome'] ?? ''));

        // Writing to the shared memory corpus needs memory:write; a key
        // without it still closes the task, it just cannot store learnings.
        $learnings = $args['learnings_summary'] ?? null;
        $learningsBlocked = $learnings !== null && ! $key->hasScope(ApiScope::MemoryWrite);

        $this->evaluator->closeTask(
            $task,
            $learningsBlocked ? null : $learnings,
            $outcome,
            isset($args['notes']) ? (string) $args['notes'] : null,
        );

        $result = ['task_id' => $task->ulid, 'status' => 'closed'];

        if ($learningsBlocked) {
            $result['learnings_stored'] = false;
            $result['note'] = 'Learnings not stored: memory:write scope missing.';
        }

        return $this->toolResult($id, $result);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function ownedTask(array $args, ApiClient $client, ApiKey $key): ?BuddyTask
    {
        $task = BuddyTask::query()->where('ulid', (string) ($args['task_id'] ?? ''))->first();

        if ($task === null) {
            return null;
        }

        if ($task->api_client_id === null || $key->hasScope(ApiScope::Admin)) {
            return $task;
        }

        return $task->api_client_id === $client->id ? $task : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function toolResult(mixed $id, array $payload): array
    {
        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_PRETTY_PRINT)]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toolError(mixed $id, string $message): array
    {
        return $this->result($id, [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => $message]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    protected function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
