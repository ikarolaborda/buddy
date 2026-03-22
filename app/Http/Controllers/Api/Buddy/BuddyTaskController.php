<?php

namespace App\Http\Controllers\Api\Buddy;

use App\DTOs\ProblemPacket;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buddy\AttachArtifactRequest;
use App\Http\Requests\Buddy\CloseTaskRequest;
use App\Http\Requests\Buddy\CreateTaskRequest;
use App\Http\Resources\Buddy\BuddyTaskResource;
use App\Jobs\EvaluateTaskJob;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('throttle:60,1')]
class BuddyTaskController extends Controller
{
    public function __construct(
        protected EvaluatorOptimizerService $evaluator,
    ) {}

    public function store(CreateTaskRequest $request): JsonResponse
    {
        $packet = ProblemPacket::fromArray($request->validated());
        $task = $this->evaluator->createTask($packet);

        // Store any inline artifacts
        foreach ($request->input('artifacts', []) as $artifact) {
            $task->artifacts()->create([
                'type' => $artifact['type'],
                'content' => $artifact['content'],
                'metadata' => $artifact['metadata'] ?? null,
            ]);
        }

        $task->loadCount(['runs', 'artifacts']);

        return (new BuddyTaskResource($task))
            ->response()
            ->setStatusCode(201);
    }

    public function show(BuddyTask $task): BuddyTaskResource
    {
        $task->loadCount(['runs', 'artifacts']);
        $task->load('recommendations');

        return new BuddyTaskResource($task);
    }

    public function attachArtifact(AttachArtifactRequest $request, BuddyTask $task): JsonResponse
    {
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
        if ($task->isTerminal()) {
            return response()->json([
                'error' => 'Cannot evaluate a task in terminal state.',
            ], 422);
        }

        // Async mode: dispatch to queue and return immediately
        if ($request->boolean('async')) {
            if (config('queue.default') === 'sync') {
                return response()->json([
                    'error' => 'Async evaluation requires a non-sync queue driver.',
                ], 422);
            }

            EvaluateTaskJob::dispatch($task);

            $task->update(['status' => TaskStatus::Evaluating]);

            return response()->json([
                'task_id' => $task->ulid,
                'status' => 'evaluating',
                'message' => 'Evaluation dispatched to queue. Poll GET /api/buddy/tasks/{id} for status.',
            ], 202);
        }

        // Synchronous mode
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
            return response()->json([
                'error' => 'Evaluation failed.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function refine(BuddyTask $task): JsonResponse
    {
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
            return response()->json([
                'error' => 'Refinement failed.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function close(CloseTaskRequest $request, BuddyTask $task): JsonResponse
    {
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
}
