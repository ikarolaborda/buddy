<?php

namespace App\Services\Memory;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryCandidate;
use App\DTOs\MemoryFeedback;
use App\DTOs\MemoryHealth;
use App\DTOs\MemoryQuery;
use App\DTOs\MemoryReceipt;
use App\DTOs\MemorySearchPage;
use App\Enums\MemoryBackend;
use App\Services\QdrantMemoryService;
use Illuminate\Support\Facades\Log;

class LegacyQdrantMemoryGateway implements MemoryGateway
{
    public function __construct(
        protected QdrantMemoryService $qdrant,
    ) {}

    public function search(MemoryQuery $query): MemorySearchPage
    {
        try {
            $results = $this->qdrant->search($query->query, $query->limit, $query->filters);
        } catch (\Throwable $e) {
            Log::warning('Legacy memory search degraded', ['error' => $e->getMessage()]);

            return MemorySearchPage::degraded(MemoryBackend::Legacy->value, $e->getMessage());
        }

        return new MemorySearchPage(
            results: $results,
            backend: MemoryBackend::Legacy->value,
        );
    }

    public function store(MemoryCandidate $candidate): ?MemoryReceipt
    {
        try {
            $pointId = $this->qdrant->store($candidate->summary, array_merge($candidate->payload, [
                'tags' => $candidate->tags,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Legacy memory store failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($pointId === null) {
            return null;
        }

        return new MemoryReceipt(
            memoryId: $pointId,
            backend: MemoryBackend::Legacy->value,
        );
    }

    public function feedback(MemoryFeedback $feedback): void
    {
        Log::info('Memory feedback recorded locally; legacy backend has no feedback API', [
            'memory_id' => $feedback->memoryId,
            'useful' => $feedback->useful,
        ]);
    }

    public function health(): MemoryHealth
    {
        $healthy = $this->qdrant->ensureCollectionExists();

        return new MemoryHealth(
            healthy: $healthy,
            backend: MemoryBackend::Legacy->value,
        );
    }
}
