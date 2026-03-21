<?php

namespace App\Mcp\Tools;

use App\Enums\ArtifactType;
use App\Mcp\BaseMcpTool;
use App\Models\BuddyTask;

class AttachArtifactMcpTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.attach_artifact';
    }

    public function description(): string
    {
        return 'Attach evidence (logs, test output, stack traces, code snippets) to an existing Buddy task.';
    }

    public function inputSchema(): array
    {
        $types = array_map(fn (ArtifactType $t) => $t->value, ArtifactType::cases());

        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'string', 'description' => 'The ULID of the task.'],
                'artifact_type' => ['type' => 'string', 'enum' => $types, 'description' => 'Type of artifact.'],
                'content' => ['type' => 'string', 'description' => 'The artifact content.'],
                'metadata' => ['type' => 'object', 'description' => 'Optional metadata.'],
            ],
            'required' => ['task_id', 'artifact_type', 'content'],
        ];
    }

    public function handle(array $arguments): array
    {
        $task = BuddyTask::where('ulid', $arguments['task_id'])->first();

        if (! $task) {
            return $this->textResponse('Task not found.');
        }

        if ($task->isTerminal()) {
            return $this->textResponse('Cannot attach artifacts to a closed or terminal task.');
        }

        $artifact = $task->artifacts()->create([
            'type' => $arguments['artifact_type'],
            'content' => $arguments['content'],
            'metadata' => $arguments['metadata'] ?? null,
        ]);

        return $this->textResponse(json_encode([
            'attached' => true,
            'artifact_id' => $artifact->id,
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
