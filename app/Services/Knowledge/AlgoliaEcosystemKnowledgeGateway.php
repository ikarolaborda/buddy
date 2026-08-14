<?php

namespace App\Services\Knowledge;

use App\Contracts\EcosystemKnowledgeGateway;
use App\DTOs\EcosystemKnowledgeHit;
use App\DTOs\EcosystemKnowledgePage;
use App\DTOs\EcosystemKnowledgeQuery;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AlgoliaEcosystemKnowledgeGateway implements EcosystemKnowledgeGateway
{
    public function search(EcosystemKnowledgeQuery $query): EcosystemKnowledgePage
    {
        if ($query->query === '' || $query->indices === []) {
            return new EcosystemKnowledgePage([]);
        }

        try {
            $response = $this->client()->post('/1/indexes/*/queries', [
                'requests' => array_map(fn (string $index): array => [
                    'indexName' => $index,
                    'params' => [
                        'query' => $query->query,
                        'hitsPerPage' => $query->limit,
                        'analytics' => false,
                        'clickAnalytics' => false,
                        'getRankingInfo' => true,
                        'filters' => 'visibility:internal AND status:current',
                        'attributesToRetrieve' => [
                            'objectID', 'canonical_id', 'title', 'body', 'snippet', 'product',
                            'repository', 'content_type', 'source_path', 'source_revision',
                            'source_url', 'tags',
                        ],
                    ],
                ], $query->indices),
                'strategy' => 'none',
            ]);

            if (! $response->successful()) {
                return EcosystemKnowledgePage::degraded("Algolia search returned {$response->status()}");
            }

            return new EcosystemKnowledgePage($this->mergeResults(
                (array) $response->json('results', []),
                $query,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Algolia ecosystem knowledge search degraded', [
                'exception' => $exception::class,
            ]);

            return EcosystemKnowledgePage::degraded($exception::class);
        }
    }

    protected function client(): PendingRequest
    {
        $applicationId = (string) config('buddy.knowledge.algolia.application_id');

        return Http::baseUrl("https://{$applicationId}-dsn.algolia.net")
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Algolia-Application-Id' => $applicationId,
                'X-Algolia-API-Key' => (string) config('buddy.knowledge.algolia.search_key'),
            ])
            ->connectTimeout((float) config('buddy.knowledge.algolia.connect_timeout', 0.2))
            ->timeout((float) config('buddy.knowledge.algolia.timeout', 0.8));
    }

    /**
     * @param  array<int, mixed>  $resultSets
     * @return array<int, EcosystemKnowledgeHit>
     */
    protected function mergeResults(array $resultSets, EcosystemKnowledgeQuery $query): array
    {
        $hits = [];

        foreach ($resultSets as $setOffset => $resultSet) {
            if (! is_array($resultSet)) {
                continue;
            }

            $index = $query->indices[$setOffset] ?? 'unknown';
            $boost = $query->product !== null && str_contains($index, "_{$query->product}_") ? 2.0 : 1.0;

            foreach ((array) ($resultSet['hits'] ?? []) as $rank => $hit) {
                if (! is_array($hit) || ! isset($hit['objectID'])) {
                    continue;
                }

                $canonicalId = (string) ($hit['canonical_id'] ?? $hit['objectID']);
                $score = $boost / (60 + $rank + 1);
                $candidate = $this->mapHit($hit, $index, $score);

                if (! isset($hits[$canonicalId]) || $candidate->score > $hits[$canonicalId]->score) {
                    $hits[$canonicalId] = $candidate;
                }
            }
        }

        uasort($hits, static fn (EcosystemKnowledgeHit $left, EcosystemKnowledgeHit $right): int => $right->score <=> $left->score);

        return array_slice(array_values($hits), 0, $query->limit);
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    protected function mapHit(array $hit, string $index, float $score): EcosystemKnowledgeHit
    {
        $maxSnippet = max(100, (int) config('buddy.knowledge.max_snippet_chars', 800));
        $snippet = Str::squish((string) ($hit['snippet'] ?? $hit['body'] ?? ''));

        return new EcosystemKnowledgeHit(
            recordId: (string) $hit['objectID'],
            index: $index,
            score: $score,
            title: (string) ($hit['title'] ?? ''),
            snippet: Str::limit($snippet, $maxSnippet, ''),
            product: (string) ($hit['product'] ?? ''),
            repository: (string) ($hit['repository'] ?? ''),
            contentType: (string) ($hit['content_type'] ?? ''),
            sourcePath: (string) ($hit['source_path'] ?? ''),
            sourceRevision: (string) ($hit['source_revision'] ?? ''),
            sourceUrl: isset($hit['source_url']) ? (string) $hit['source_url'] : null,
            tags: array_values(array_filter((array) ($hit['tags'] ?? []), 'is_string')),
        );
    }
}
