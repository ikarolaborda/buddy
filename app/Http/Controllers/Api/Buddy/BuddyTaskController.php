<?php

namespace App\Http\Controllers\Api\Buddy;

use App\DTOs\ProblemPacket;
use App\Enums\ApiScope;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buddy\AttachArtifactRequest;
use App\Http\Requests\Buddy\CloseTaskRequest;
use App\Http\Requests\Buddy\CreateTaskRequest;
use App\Http\Resources\Buddy\BuddyTaskResource;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Models\IdempotencyRecord;
use App\Services\EvaluatorOptimizerService;
use App\Services\IdempotencyService;
use App\Services\OutboxPublisher;
use App\Services\TaskStateService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Middleware('throttle:60,1')]
class BuddyTaskController extends Controller
{
    public function __construct(
        protected EvaluatorOptimizerService $evaluator,
        protected IdempotencyService $idempotency,
        protected OutboxPublisher $outbox,
        protected TaskStateService $state,
    ) {}

    public function store(CreateTaskRequest $request): JsonResponse
    {
        $client = $request->attributes->get('api_client');
        $idempotencyKey = $request->header('Idempotency-Key');
        $requestHash = $this->idempotency->hashRequest($request->validated());

        $reservation = null;

        if ($client instanceof ApiClient) {
            if ($idempotencyKey === null || $idempotencyKey === '') {
                return response()->json([
                    'error' => 'Idempotency-Key header is required for task submission.',
                ], 422);
            }

            $result = $this->reserveIdempotencyKey($client, $idempotencyKey, $requestHash);

            if ($result instanceof JsonResponse) {
                return $result;
            }

            $reservation = $result;
        }

        $packet = ProblemPacket::fromArray($request->validated());

        $task = DB::transaction(function () use ($request, $packet, $client) {
            $task = $this->evaluator->createTask($packet, $client?->id);

            foreach ($request->input('artifacts', []) as $artifact) {
                $task->artifacts()->create([
                    'type' => $artifact['type'],
                    'content' => $artifact['content'],
                    'metadata' => $artifact['metadata'] ?? null,
                ]);
            }

            return $task;
        });

        $task->loadCount(['runs', 'artifacts']);

        $response = (new BuddyTaskResource($task))
            ->response()
            ->setStatusCode(201);

        if ($reservation instanceof IdempotencyRecord) {
            $reservation->update([
                'buddy_task_id' => $task->id,
                'response_status' => 201,
                'response_body' => $response->getData(true),
            ]);
        }

        return $response;
    }

    public function show(Request $request, BuddyTask $task): BuddyTaskResource
    {
        $this->authorizeClientAccess($request, $task);

        $task->loadCount(['runs', 'artifacts']);
        $task->load('recommendations');

        return new BuddyTaskResource($task);
    }

    /*
     * Cross-client isolation (plan §13: zero cross-client data exposure).
     * A non-owner gets 404, not 403, so task existence does not leak.
     * Tasks without an owning client (created while auth was disabled)
     * remain accessible; admin-scoped keys bypass.
     */
    protected function authorizeClientAccess(Request $request, BuddyTask $task): void
    {
        if (! config('buddy.api.auth_required') || $task->api_client_id === null) {
            return;
        }

        $key = $request->attributes->get('api_key');

        if ($key !== null && $key->hasScope(ApiScope::Admin)) {
            return;
        }

        $client = $request->attributes->get('api_client');

        if ($client === null || $client->id !== $task->api_client_id) {
            abort(404);
        }
    }

    public function attachArtifact(AttachArtifactRequest $request, BuddyTask $task): JsonResponse
    {
        $this->authorizeClientAccess($request, $task);

        if ($task->isTerminal()) {
            return response()->json([
                'error' => 'Cannot attach artifacts to a task in terminal state.',
            ], 422);
        }

        $artifact = $task->artifacts()->create($request->validated());

        return response()->json([
            'id' => $artifact->id,
            'type' => $artifact->type->value,
            'created_at' => $artifact->created_at?->toISOString(),
        ], 201);
    }

    public function evaluate(Request $request, BuddyTask $task): JsonResponse
    {
        $this->authorizeClientAccess($request, $task);

        if ($task->isTerminal()) {
            return response()->json([
                'error' => 'Cannot evaluate a task in terminal state.',
            ], 422);
        }

        // Async is the default (plan §8.1: enqueue only, 202). Inline
        // evaluation blocks a server worker for up to the provider
        // timeout, which starves the fixed Octane worker pool (ADR
        // 0008); it remains available behind ?sync=1 and is the
        // automatic fallback when no real queue driver exists.
        $wantsSync = $request->boolean('sync') || config('queue.default') === 'sync';

        if (! $wantsSync) {
            DB::transaction(function () use ($task) {
                if ($task->status === TaskStatus::Pending) {
                    $this->state->transition($task, TaskStatus::Evaluating);
                }

                $this->outbox->appendTaskSubmitted($task);
            });

            return response()->json([
                'task_id' => $task->ulid,
                'status' => 'evaluating',
                'message' => 'Evaluation dispatched to queue. Poll GET /api/buddy/tasks/{id} for status.',
            ], 202);
        }

        try {
            $result = $this->evaluator->evaluate($task);

            $task->refresh();
            $task->loadCount(['runs', 'artifacts']);
            $task->load('recommendations');

            return response()->json([
                'task' => new BuddyTaskResource($task),
                'evaluation' => $result->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Evaluation failed', [
                'task_ulid' => $task->ulid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Evaluation failed.',
            ], 500);
        }
    }

    public function refine(Request $request, BuddyTask $task): JsonResponse
    {
        $this->authorizeClientAccess($request, $task);

        if ($task->isTerminal()) {
            return response()->json([
                'error' => 'Cannot refine a task in terminal state.',
            ], 422);
        }

        try {
            $result = $this->evaluator->refine($task);

            $task->refresh();
            $task->loadCount(['runs', 'artifacts']);

            return response()->json([
                'task' => new BuddyTaskResource($task),
                'refinement' => $result->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Refinement failed', [
                'task_ulid' => $task->ulid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Refinement failed.',
            ], 500);
        }
    }

    public function close(CloseTaskRequest $request, BuddyTask $task): JsonResponse
    {
        $this->authorizeClientAccess($request, $task);

        if ($task->status->isTerminal() && $task->status !== TaskStatus::Completed) {
            return response()->json([
                'error' => 'Cannot close a task that is already in a terminal state.',
            ], 422);
        }

        $this->evaluator->closeTask(
            $task,
            $request->input('learnings_summary'),
        );

        return response()->json([
            'task_id' => $task->ulid,
            'status' => 'closed',
        ]);
    }

    protected function reserveIdempotencyKey(
        ApiClient $client,
        string $key,
        string $requestHash,
    ): IdempotencyRecord|JsonResponse {
        try {
            return IdempotencyRecord::create([
                'api_client_id' => $client->id,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'expires_at' => now()->addDay(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->idempotency->find($client, $key);

            if ($existing === null) {
                return response()->json(['error' => 'Idempotency conflict.'], 409);
            }

            if ($existing->request_hash !== $requestHash) {
                return response()->json([
                    'error' => 'Idempotency-Key was already used with a different request payload.',
                ], 409);
            }

            if ($existing->response_body === null) {
                return response()->json([
                    'error' => 'A request with this Idempotency-Key is still being processed.',
                ], 409);
            }

            return response()
                ->json($existing->response_body, (int) $existing->response_status)
                ->header('Idempotency-Replayed', 'true');
        }
    }
}
