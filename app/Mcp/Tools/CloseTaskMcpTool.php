<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\BaseMcpTool;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;

class CloseTaskMcpTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.close_task';
    }

    public function description(): string
    {
        return 'Close a Buddy task and optionally store durable learnings from the investigation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => 'The ULID of the task.'],
                'learnings_summary' => ['type' => 'string', 'description' => 'Optional summary of learnings to store in memory.'],
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

        if ($task->status->isTerminal() && $task->status !== TaskStatus::Completed) {
            return $this->textResponse('Cannot close a task that is already in a terminal state.');
        }

        /** @var EvaluatorOptimizerService $evaluator */
        $evaluator = app(EvaluatorOptimizerService::class);
        $evaluator->closeTask($task, $arguments['learnings_summary'] ?? null);

        return $this->textResponse(json_encode([
            'closed' => true,
            'task_id' => $task->ulid,
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
