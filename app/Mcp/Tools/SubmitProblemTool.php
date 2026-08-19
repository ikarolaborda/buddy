<?php

namespace App\Mcp\Tools;

use App\DTOs\ProblemPacket;
use App\Enums\TaskStatus;
use App\Mcp\BaseMcpTool;
use App\Services\EvaluatorOptimizerService;
use App\Services\OutboxPublisher;
use App\Services\TaskStateService;
use Illuminate\Support\Facades\DB;

class SubmitProblemTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.submit_problem';
    }

    public function description(): string
    {
        return 'Submit a structured problem packet to Buddy for evaluation. Returns a task ID for tracking.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_agent' => ['type' => 'string', 'description' => 'Identifier of the calling agent.'],
                'task_summary' => ['type' => 'string', 'description' => 'Description of the problem.'],
                'problem_type' => ['type' => 'string', 'enum' => ['bug', 'test_failure', 'performance', 'architecture', 'integration', 'configuration', 'security', 'ambiguous', 'other']],
                'repo' => ['type' => 'string', 'description' => 'Repository identifier.'],
                'branch' => ['type' => 'string', 'description' => 'Branch name.'],
                'constraints' => ['type' => 'array', 'items' => ['type' => 'string']],
                'evidence' => ['type' => 'array', 'items' => ['type' => 'object']],
                'requested_outcome' => ['type' => 'string'],
            ],
            'required' => ['source_agent', 'task_summary', 'problem_type'],
        ];
    }

    public function handle(array $arguments): array
    {
        $packet = ProblemPacket::fromArray($arguments);

        /** @var EvaluatorOptimizerService $evaluator */
        $evaluator = app(EvaluatorOptimizerService::class);

        // Submitting a problem IS the request to evaluate it. createTask()
        // appends only the knowledge-prefetch message, so without the
        // task-submitted message nothing ever dispatches EvaluateTaskJob and
        // the task sits Pending with runs=0 while the agent polls forever.
        $task = DB::transaction(function () use ($evaluator, $packet) {
            $task = $evaluator->createTask($packet);

            app(TaskStateService::class)->transition($task, TaskStatus::Evaluating);
            app(OutboxPublisher::class)->appendTaskSubmitted($task);

            return $task;
        });

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'task_id' => $task->ulid,
                        'status' => $task->status->value,
                        'message' => 'Problem submitted and evaluation dispatched. Poll buddy.get_task_status, then buddy.get_recommendation.',
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ];
    }
}
