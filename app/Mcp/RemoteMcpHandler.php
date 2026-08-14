<?php

namespace App\Mcp;

use App\DTOs\ProblemPacket;
use App\Enums\ApiScope;
use App\Enums\ProblemType;
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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    public function __construct(
        protected EvaluatorOptimizerService $evaluator,
        protected OutboxPublisher $outbox,
        protected TaskStateService $state,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null means "202, no body"
     */
    public function handle(array $message, ApiClient $client, ApiKey $key, ?RequestContext $context = null): ?array
    {
        $id = $message['id'] ?? null;
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';
        $context ??= RequestContext::fromMessage($message);

        // The 2026-07-28 transport does not define request-metadata headers
        // for notification POSTs. Requests, by contrast, are fail-closed so a
        // proxy can never route on a header that disagrees with the body.
        if ($context->isModern() && array_key_exists('id', $message)) {
            $context->validateModernRequest($message);
        }

        $this->observe($method, $context, $client);

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        if ($context->isModern()) {
            $response = match ($method) {
                'server/discover' => $this->discover($id),
                'ping' => $this->result($id, new \stdClass),
                'prompts/list' => $this->result($id, $this->cacheable(['prompts' => []])),
                'resources/list' => $this->result($id, $this->cacheable(['resources' => []])),
                'tools/list' => $this->result($id, $this->cacheable(['tools' => RemoteToolDefinitions::all()])),
                'tools/call' => $this->call($id, $message['params'] ?? [], $client, $key),
                default => throw McpProtocolException::methodNotFound($method),
            };

            return $this->modernize($response);
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => ProtocolVersions::negotiate(
                    $context->protocolVersion,
                    $context->label() ?? $client->name,
                ),
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

    /**
     * @return array<string, mixed>
     */
    protected function discover(mixed $id): array
    {
        return $this->result($id, $this->cacheable([
            'supportedVersions' => ProtocolVersions::MODERN_SUPPORTED,
            'capabilities' => [
                'tools' => new \stdClass,
                'resources' => new \stdClass,
                'prompts' => new \stdClass,
            ],
            'instructions' => UsageInstructions::forInitialize(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function cacheable(array $result): array
    {
        return $result + [
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
        ];
    }

    /**
     * Add the per-result fields required by the stateless protocol without
     * changing the legacy response shape used during the compatibility window.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function modernize(array $response): array
    {
        $result = $response['result'] ?? null;

        if ($result instanceof \stdClass) {
            $result = [];
        }

        if (! is_array($result)) {
            return $response;
        }

        $result['resultType'] ??= 'complete';
        $meta = $result['_meta'] ?? [];
        $meta = is_array($meta) ? $meta : [];
        $meta['io.modelcontextprotocol/serverInfo'] = $this->serverInfo();
        $result['_meta'] = $meta;
        $response['result'] = $result;

        return $response;
    }

    /**
     * @return array{name: string, version: string}
     */
    protected function serverInfo(): array
    {
        return ['name' => 'buddy', 'version' => '2.0.0'];
    }

    /*
     * Phase A of the 2026-07-28 migration: observe, do not enforce.
     *
     * The stateless revision makes Mcp-Method and Mcp-Name mandatory so that
     * gateways can route without reading the body, and moves client identity
     * into `_meta`. Before buddy depends on any of that, it needs to know what
     * the real client actually sends - rejecting requests on a header the
     * current Claude Code build may not emit yet would take the review sidecar
     * offline to enforce a rule nothing is breaking.
     *
     * Sampled rather than logged on every call: this runs on the hot path of
     * every tools/call, and a log line per request is a cost line per request.
     */
    protected function observe(string $method, RequestContext $context, ApiClient $client): void
    {
        if (! config('buddy.mcp.observe_protocol', true)) {
            return;
        }

        $rate = (int) config('buddy.mcp.observe_sample_rate', 20);

        if ($rate > 1 && random_int(1, $rate) !== 1) {
            return;
        }

        Log::info('MCP protocol telemetry', $context->telemetry($method) + [
            'api_client' => $client->name,
        ]);
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
            // A bare "Tool execution failed." is unactionable from the client:
            // the caller cannot tell a transient fault from a malformed packet,
            // and cannot correlate the failure with anything in the logs. Return
            // a reference the operator can grep for, and the exception class so
            // the caller can at least distinguish fault categories. The message
            // itself stays server-side, since it can carry query fragments.
            $errorId = (string) Str::uuid();

            Log::error('MCP tool execution failed', [
                'error_id' => $errorId,
                'tool' => $tool,
                'client_id' => $client->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            report($e);

            return $this->toolError($id, sprintf(
                'Tool execution failed [%s]. Reference: %s',
                class_basename($e),
                $errorId,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function submitProblem(mixed $id, array $args, ApiClient $client): array
    {
        // Limits mirror CreateTaskRequest: the MCP surface and the REST surface
        // write the same columns, so a rule that exists on only one of them is a
        // silent 500 waiting to happen on the other.
        $validated = Validator::validate($args, [
            'source_agent' => ['required', 'string', 'max:255'],
            'task_summary' => ['required', 'string', 'max:10000'],
            'problem_type' => ['required', 'string', Rule::enum(ProblemType::class)],
            'repo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'constraints' => ['sometimes', 'array'],
            'constraints.*' => ['string'],
            'evidence' => ['sometimes', 'array'],
            'requested_outcome' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $task = DB::transaction(fn () => $this->evaluator->createTask(
            ProblemPacket::fromArray($validated),
            $client->id,
        ));

        return $this->toolResult($id, [
            'task_id' => $task->ulid,
            'status' => $task->status->value,
            'knowledge_context_status' => $task->knowledge_context_status,
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
                'knowledge_hits' => $recommendation->knowledge_hits,
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
