<?php

namespace App\Ai\Tools;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryQuery;
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
        $page = app(MemoryGateway::class)->search(new MemoryQuery(
            query: $request['query'],
            limit: $request['limit'] ?? 5,
        ));

        if ($page->degraded) {
            return "Memory grounding is unavailable ({$page->degradedReason}). "
                .'State explicitly in your result that memory could not be consulted.';
        }

        if ($page->results === []) {
            return 'No relevant memories found.';
        }

        $formatted = array_map(fn ($r) => [
            'memory_id' => $r->pointId,
            'score' => round($r->score, 3),
            'summary' => $r->summary,
            'tags' => $r->tags,
        ], $page->results);

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
