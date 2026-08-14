<?php

namespace App\Services\Knowledge\Indexing;

final class KnowledgeIndexSettings
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'searchableAttributes' => [
                'unordered(title)',
                'unordered(symbol_name)',
                'unordered(tags)',
                'unordered(body)',
                'unordered(source_path)',
            ],
            'attributesForFaceting' => [
                'filterOnly(visibility)',
                'filterOnly(status)',
                'filterOnly(environment)',
                'filterOnly(product)',
                'filterOnly(repository)',
                'searchable(content_type)',
                'searchable(tags)',
            ],
            'attributesToSnippet' => ['body:30'],
            'attributesToHighlight' => [],
            'customRanking' => ['desc(indexed_at_ts)'],
            'typoTolerance' => true,
        ];
    }
}
