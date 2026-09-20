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
                'defer_evaluation' => ['type' => 'boolean', 'description' => 'Leave the task Pending instead of evaluating at once. Set this whenever you will call buddy.attach_artifact: the evaluator reads artifacts when it compiles its prompt, so evidence attached to an already-running evaluation is stored but not read. Attach first, then call buddy.evaluate_task.'],
            ],
            'required' => ['source_agent', 'task_summary', 'problem_type'],
        ];
    }

    public function handle(array $arguments): array
    {
        $packet = ProblemPacket::fromArray($arguments);
        $deferred = (bool) ($arguments['defer_evaluation'] ?? false);

        /** @var EvaluatorOptimizerService $evaluator */
        $evaluator = app(EvaluatorOptimizerService::class);

        // Submitting a problem IS the request to evaluate it, unless the caller
        // says it has evidence to attach first. createTask() appends only the
        // knowledge-prefetch message, so without the task-submitted message
        // nothing ever dispatches EvaluateTaskJob and the task sits Pending
        // with runs=0 while the agent polls forever. Deferring is the one case
        // where Pending is wanted: see RemoteMcpHandler::submitProblem.
        $task = DB::transaction(function () use ($evaluator, $packet, $deferred) {
            $task = $evaluator->createTask($packet);

            if ($deferred) {
                return $task;
            }

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
                        'message' => $deferred
                            ? 'Problem submitted, evaluation deferred. Attach evidence with buddy.attach_artifact, then call buddy.evaluate_task.'
                            : 'Problem submitted and evaluation dispatched. Poll buddy.get_task_status, then buddy.get_recommendation.',
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ];
    }
}
