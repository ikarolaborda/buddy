<?php

namespace App\Services\Knowledge;

use App\DTOs\EcosystemKnowledgeQuery;
use App\Models\BuddyTask;
use Illuminate\Support\Str;

class EcosystemKnowledgeQueryFactory
{
    public function forTask(BuddyTask $task): EcosystemKnowledgeQuery
    {
        $repository = trim((string) $task->repo);
        $product = $this->productForRepository($repository);
        $query = $this->sanitize($task->task_summary);

        return new EcosystemKnowledgeQuery(
            query: $query,
            indices: $this->indicesForProduct($product),
            limit: max(1, (int) config('buddy.knowledge.max_hits', 6)),
            product: $product,
            repository: $repository !== '' ? $repository : null,
        );
    }

    public function sanitize(string $query): string
    {
        $query = preg_replace('/\bBearer\s+\S+/i', '[redacted]', $query) ?? $query;
        $query = preg_replace('/\b(api[_-]?key|token|secret|password)\s*[:=]\s*\S+/i', '$1=[redacted]', $query) ?? $query;
        $query = preg_replace('/\b[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\b/', '[redacted-jwt]', $query) ?? $query;
        $query = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $query) ?? $query;
        $query = preg_replace_callback('/https?:\/\/[^\s]+/i', static function (array $matches): string {
            $parts = parse_url($matches[0]);

            if (! is_array($parts) || ! isset($parts['host'])) {
                return '[redacted-url]';
            }

            return ($parts['scheme'] ?? 'https').'://'.$parts['host'].($parts['path'] ?? '');
        }, $query) ?? $query;
        $query = preg_replace('/\b(?:[a-f0-9]{32,}|[A-Za-z0-9+\/_-]{40,}={0,2})\b/i', '[redacted-high-entropy]', $query) ?? $query;
        $query = Str::squish($query);

        return Str::limit($query, 500, '');
    }

    public function productForRepository(string $repository): ?string
    {
        $repository = Str::lower($repository);

        return match (true) {
            str_contains($repository, 'theravista') => 'theravista',
            str_contains($repository, 'falinha') => 'falinha',
            str_contains($repository, 'ritmovida'), str_contains($repository, 'heartbeatapp') => 'ritmovida',
            str_contains($repository, 'buddy'), str_contains($repository, 'qdrant-memory') => 'buddy',
            str_contains($repository, 'aerolambda-site') => 'aerolambda',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    protected function indicesForProduct(?string $product): array
    {
        $environment = (string) config('buddy.knowledge.environment', 'staging');
        $products = array_keys((array) config('buddy.knowledge.products', []));

        if ($product !== null) {
            $products = array_values(array_unique([$product, 'aerolambda']));
        }

        return array_map(
            static fn (string $name): string => "{$environment}_internal_{$name}_knowledge",
            $products,
        );
    }
}
