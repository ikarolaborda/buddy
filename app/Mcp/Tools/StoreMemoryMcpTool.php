<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseMcpTool;
use App\Services\QdrantMemoryService;

class StoreMemoryMcpTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.store_memory';
    }

    public function description(): string
    {
        return 'Store a distilled engineering memory (decision, fix, failure, pattern) for future retrieval.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'Concise summary of the knowledge to store.'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags for filtering.'],
                'task_intent' => ['type' => 'string', 'description' => 'The intent: bugfix, feature, refactor, etc.'],
                'stack' => ['type' => 'string', 'description' => 'Technology stack involved.'],
                'subsystem' => ['type' => 'string', 'description' => 'Affected subsystem or module.'],
                'symptom' => ['type' => 'string', 'description' => 'Observable symptom.'],
                'root_cause' => ['type' => 'string', 'description' => 'Identified root cause.'],
                'solution_pattern' => ['type' => 'string', 'description' => 'Applied solution pattern.'],
                'outcome' => ['type' => 'string', 'description' => 'Outcome: resolved, partial, failed.'],
            ],
            'required' => ['summary'],
        ];
    }

    public function handle(array $arguments): array
    {
        /** @var QdrantMemoryService $memory */
        $memory = app(QdrantMemoryService::class);

        $payload = array_filter([
            'tags' => $arguments['tags'] ?? [],
            'task_intent' => $arguments['task_intent'] ?? null,
            'stack' => $arguments['stack'] ?? null,
            'subsystem' => $arguments['subsystem'] ?? null,
            'symptom' => $arguments['symptom'] ?? null,
            'root_cause' => $arguments['root_cause'] ?? null,
            'solution_pattern' => $arguments['solution_pattern'] ?? null,
            'outcome' => $arguments['outcome'] ?? null,
        ]);

        $pointId = $memory->store($arguments['summary'], $payload);

        if (! $pointId) {
            return $this->textResponse('Failed to store memory. Check Qdrant connection and OpenAI API key.');
        }

        return $this->textResponse(json_encode([
            'stored' => true,
            'point_id' => $pointId,
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
