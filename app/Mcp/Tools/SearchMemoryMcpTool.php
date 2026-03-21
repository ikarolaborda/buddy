<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseMcpTool;
use App\Services\QdrantMemoryService;

class SearchMemoryMcpTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.search_memory';
    }

    public function description(): string
    {
        return 'Search Buddy\'s episodic memory for past engineering episodes, decisions, fixes, and patterns.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Natural language search query.'],
                'limit' => ['type' => 'integer', 'description' => 'Max results.', 'default' => 5],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filter by tags.'],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(array $arguments): array
    {
        /** @var QdrantMemoryService $memory */
        $memory = app(QdrantMemoryService::class);

        $filters = [];
        if (! empty($arguments['tags'])) {
            $filters['tags'] = $arguments['tags'];
        }

        $results = $memory->search(
            query: $arguments['query'],
            limit: $arguments['limit'] ?? 5,
            filters: $filters,
        );

        if ($results === []) {
            return $this->textResponse('No relevant memories found.');
        }

        $formatted = array_map(fn ($r) => [
            'score' => round($r->score, 3),
            'summary' => $r->summary,
            'tags' => $r->tags,
        ], $results);

        return $this->textResponse(json_encode($formatted, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
