<?php

namespace App\Services\Memory;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryCandidate;
use App\DTOs\MemoryFeedback;
use App\DTOs\MemoryHealth;
use App\DTOs\MemoryQuery;
use App\DTOs\MemoryReceipt;
use App\DTOs\MemorySearchPage;
use App\DTOs\MemorySearchResult;
use App\Enums\MemoryBackend;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Talks to the Go qdrant-memory hub's authenticated REST interface.
 * Buddy never addresses a Qdrant collection directly through this path;
 * tenancy, curation, embedding, and hybrid retrieval stay inside the
 * hub. The endpoint contract is frozen against the hub's openapi.yaml
 * (see config/buddy.php) and re-verified at the Phase 2 staging gate.
 */
class HubMemoryGateway implements MemoryGateway
{
    public function search(MemoryQuery $query): MemorySearchPage
    {
        try {
            $response = $this->client()->post($this->path('search'), array_filter([
                'query' => $query->query,
                'limit' => $query->limit,
                'filters' => $query->filters !== [] ? $query->filters : null,
            ], fn ($v) => $v !== null));

            if (! $response->successful()) {
                return MemorySearchPage::degraded(
                    MemoryBackend::Hub->value,
                    "hub search returned {$response->status()}",
                );
            }

            $results = array_map(
                function (array $r) {
                    $episode = $r['episode'] ?? [];

                    return new MemorySearchResult(
                        pointId: (string) ($r['id'] ?? ''),
                        score: (float) ($r['score'] ?? 0.0),
                        summary: (string) ($episode['summary'] ?? $episode['problem'] ?? ''),
                        payload: $episode,
                        tags: $episode['tags'] ?? [],
                    );
                },
                $response->json('results') ?? [],
            );

            return new MemorySearchPage(
                results: $results,
                backend: MemoryBackend::Hub->value,
            );
        } catch (\Throwable $e) {
            Log::warning('Hub memory search degraded', ['error' => $e->getMessage()]);

            return MemorySearchPage::degraded(MemoryBackend::Hub->value, $e->getMessage());
        }
    }

    public function store(MemoryCandidate $candidate): ?MemoryReceipt
    {
        try {
            $response = $this->client()->post($this->path('store'), array_filter([
                'problem' => $candidate->problem ?? $candidate->summary,
                'solution' => $candidate->solution ?? $candidate->summary,
                'impact' => $candidate->impact ?? '',
                'tags' => $candidate->tags,
                'file_references' => $candidate->fileReferences,
                'source_system' => 'buddy',
            ], fn ($v) => $v !== null && $v !== []));

            if (! $response->successful()) {
                Log::warning('Hub memory store failed', ['status' => $response->status()]);

                return null;
            }

            $memoryId = (string) ($response->json('id') ?? '');

            if ($memoryId === '') {
                return null;
            }

            return new MemoryReceipt(
                memoryId: $memoryId,
                backend: MemoryBackend::Hub->value,
            );
        } catch (\Throwable $e) {
            Log::warning('Hub memory store failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function feedback(MemoryFeedback $feedback): void
    {
        try {
            $path = str_replace('{id}', rawurlencode($feedback->memoryId), $this->path('feedback'));

            $this->client()->post($path, [
                'kind' => $feedback->useful ? 'useful' : 'not_useful',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Hub memory feedback failed', ['error' => $e->getMessage()]);
        }
    }

    public function health(): MemoryHealth
    {
        try {
            $response = $this->client()->get($this->path('health'));

            return new MemoryHealth(
                healthy: $response->successful(),
                backend: MemoryBackend::Hub->value,
                details: ['status' => $response->status()],
            );
        } catch (\Throwable $e) {
            return new MemoryHealth(
                healthy: false,
                backend: MemoryBackend::Hub->value,
                details: ['error' => $e->getMessage()],
            );
        }
    }

    protected function client(): PendingRequest
    {
        $client = Http::baseUrl((string) config('buddy.memory.hub.base_url'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('buddy.memory.hub.connect_timeout', 5))
            ->timeout((int) config('buddy.memory.hub.timeout', 15));

        $token = config('buddy.memory.hub.token');

        if ($token) {
            $client->withToken($token);
        }

        return $client;
    }

    protected function path(string $operation): string
    {
        return (string) config("buddy.memory.hub.paths.{$operation}");
    }
}
