<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseMcpTool;
use App\Models\BuddyTask;

class GetTaskStatusTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.get_task_status';
    }

    public function description(): string
    {
        return 'Get the current status of a Buddy task.';
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

        return $this->textResponse(json_encode([
            'task_id' => $task->ulid,
            'status' => $task->status->value,
            'problem_type' => $task->problem_type->value,
            'runs_count' => $task->runs()->count(),
            'has_recommendation' => $task->recommendations()->exists(),
            'created_at' => $task->created_at?->toISOString(),
        ], JSON_THROW_ON_ERROR));
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
