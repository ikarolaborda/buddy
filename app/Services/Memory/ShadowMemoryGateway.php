<?php

namespace App\Services\Memory;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryCandidate;
use App\DTOs\MemoryFeedback;
use App\DTOs\MemoryHealth;
use App\DTOs\MemoryQuery;
use App\DTOs\MemoryReceipt;
use App\DTOs\MemorySearchPage;
use Illuminate\Support\Facades\Log;

/*
 * Migration-phase backend: serves reads from the legacy collection while
 * mirroring every search against the hub and logging comparison metrics.
 * Writes go to the legacy backend only — blind dual-writes are avoided
 * because repeated content-addressed hub stores append revisions.
 */
class ShadowMemoryGateway implements MemoryGateway
{
    public function __construct(
        protected LegacyQdrantMemoryGateway $legacy,
        protected HubMemoryGateway $hub,
    ) {}

    public function search(MemoryQuery $query): MemorySearchPage
    {
        $primary = $this->legacy->search($query);
        $shadow = $this->hub->search($query);

        Log::info('Shadow memory search comparison', [
            'query' => $query->query,
            'legacy_count' => count($primary->results),
            'hub_count' => count($shadow->results),
            'legacy_degraded' => $primary->degraded,
            'hub_degraded' => $shadow->degraded,
        ]);

        return $primary;
    }

    public function store(MemoryCandidate $candidate): ?MemoryReceipt
    {
        return $this->legacy->store($candidate);
    }

    public function feedback(MemoryFeedback $feedback): void
    {
        $this->legacy->feedback($feedback);
    }

    public function health(): MemoryHealth
    {
        return $this->legacy->health();
    }
}
