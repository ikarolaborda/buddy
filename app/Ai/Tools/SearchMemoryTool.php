<?php

namespace App\Ai\Tools;

use App\Services\QdrantMemoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchMemoryTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search Buddy\'s episodic memory for past engineering episodes, decisions, '
            .'fixes, failures, and patterns that are similar to the given query.';
    }

    public function handle(Request $request): Stringable|string
    {
        /** @var QdrantMemoryService $memory */
        $memory = app(QdrantMemoryService::class);

        $results = $memory->search(
            query: $request['query'],
            limit: $request['limit'] ?? 5,
        );

        if ($results === []) {
            return 'No relevant memories found.';
        }

        $formatted = array_map(fn ($r) => [
            'score' => round($r->score, 3),
            'summary' => $r->summary,
            'tags' => $r->tags,
        ], $results);

        return json_encode($formatted, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language search query describing the problem, symptom, or pattern to search for.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of memory results to return.')
                ->min(1)
                ->max(20),
        ];
    }
}
