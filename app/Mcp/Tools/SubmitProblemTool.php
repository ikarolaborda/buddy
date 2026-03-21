<?php

namespace App\Mcp\Tools;

use App\DTOs\ProblemPacket;
use App\Mcp\BaseMcpTool;
use App\Services\EvaluatorOptimizerService;

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
        $task = $evaluator->createTask($packet);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'task_id' => $task->ulid,
                        'status' => $task->status->value,
                        'message' => 'Problem submitted. Use buddy.get_task_status to check or buddy.get_recommendation after evaluation.',
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ];
    }
}
