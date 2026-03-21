<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseMcpTool;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;

class GetRecommendationTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.get_recommendation';
    }

    public function description(): string
    {
        return 'Trigger evaluation and get Buddy\'s recommendation for a submitted problem. '
            .'If the task has already been evaluated, returns the existing recommendation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => 'The ULID of the task.'],
            ],
            'required' => ['task_id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $task = BuddyTask::where('ulid', $arguments['task_id'])->first();

        if (! $task) {
            return $this->textResponse('Task not found.');
        }

        $existing = $task->latestRecommendation();

        if ($existing) {
            return $this->textResponse(json_encode([
                'accepted' => $existing->accepted,
                'confidence' => $existing->confidence->value,
                'summary' => $existing->summary,
                'recommended_plan' => $existing->recommended_plan ?? [],
                'rejected_reasons' => $existing->rejected_reasons ?? [],
                'required_followups' => $existing->required_followups ?? [],
                'risks' => $existing->risks ?? [],
                'next_actions' => $existing->next_actions ?? [],
                'memory_hits' => $existing->memory_hits ?? [],
            ], JSON_THROW_ON_ERROR));
        }

        /** @var EvaluatorOptimizerService $evaluator */
        $evaluator = app(EvaluatorOptimizerService::class);
        $result = $evaluator->evaluate($task);

        return $this->textResponse(json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    protected function textResponse(string $text): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
    }
}
