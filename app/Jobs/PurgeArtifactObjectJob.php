<?php

namespace App\Jobs;

use App\Contracts\ArtifactObjectStore;
use App\Models\BuddyArtifact;
use App\Models\BuddyArtifactUpload;
use App\Services\Artifacts\ArtifactStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/*
 * Removes the bytes behind a tombstoned artifact. Idempotent: an object that
 * is already gone counts as deleted, and a failed delete throws so the retry
 * keeps the tombstone in place. Access is never restored either way.
 */
#[Tries(5)]
#[Backoff(30, 120, 600)]
#[Timeout(60)]
class PurgeArtifactObjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public int $artifactId,
    ) {
        $this->afterCommit();
    }

    public function handle(ArtifactObjectStore $store): void
    {
        $artifact = BuddyArtifact::query()->find($this->artifactId);

        if ($artifact === null || $artifact->deleted_at === null) {
            return;
        }

        if ($artifact->storage_status === ArtifactStorageService::STATUS_PURGED) {
            return;
        }

        foreach ($this->keys($artifact) as $key) {
            if (! $store->delete($key)) {
                throw new RuntimeException("Artifact object {$key} was not deleted.");
            }
        }

        BuddyArtifact::query()
            ->whereKey($artifact->id)
            ->whereNotNull('deleted_at')
            ->update(['storage_status' => ArtifactStorageService::STATUS_PURGED]);
    }

    /**
     * @return list<string>
     */
    private function keys(BuddyArtifact $artifact): array
    {
        $staging = BuddyArtifactUpload::query()
            ->where('buddy_artifact_id', $artifact->id)
            ->pluck('staging_key')
            ->all();

        return array_values(array_unique(array_filter([$artifact->object_key, ...$staging])));
    }
}
