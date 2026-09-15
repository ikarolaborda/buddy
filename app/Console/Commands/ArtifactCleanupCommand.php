<?php

namespace App\Console\Commands;

use App\Jobs\PurgeArtifactObjectJob;
use App\Models\BuddyArtifact;
use App\Models\BuddyArtifactUpload;
use App\Services\Artifacts\ArtifactStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/*
 * Periodic hygiene for R2 artifacts (plan §9): abandoned reservations give
 * their bytes back, artifacts past retention are tombstoned and purged, and
 * a purge that never ran is queued again. Every step is safe to repeat.
 */
class ArtifactCleanupCommand extends Command
{
    protected $signature = 'buddy:artifacts:cleanup
        {--dry-run : Report what would change without touching rows or objects}';

    protected $description = 'Expire stale artifact upload reservations and purge artifacts past retention';

    public function handle(ArtifactStorageService $storage): int
    {
        $dry = (bool) $this->option('dry-run');

        $expired = $this->each($this->staleReservations(), fn (BuddyArtifactUpload $upload) => $storage->expire($upload), $dry);
        $retired = $this->each($this->pastRetention(), fn (BuddyArtifact $artifact) => $storage->delete($artifact), $dry);
        $requeued = $this->each($this->unpurged(), fn (BuddyArtifact $artifact) => PurgeArtifactObjectJob::dispatch($artifact->id), $dry);
        $swept = $this->each($this->staleStaging(), fn (BuddyArtifactUpload $upload) => $storage->deleteQuietly($upload->staging_key), $dry);

        $this->info(sprintf(
            'Expired %d reservation(s), retired %d artifact(s), re-queued %d purge(s), swept %d staging object(s)%s.',
            $expired,
            $retired,
            $requeued,
            $swept,
            $dry ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    private function each(Builder $query, callable $action, bool $dry): int
    {
        if ($dry) {
            return $query->count();
        }

        $count = 0;

        foreach ($query->lazyById(200) as $model) {
            $action($model);
            $count++;
        }

        return $count;
    }

    private function staleReservations(): Builder
    {
        // An upload claimed by a finalize that never finished is released
        // after an hour; a healthy finalize takes seconds.
        return BuddyArtifactUpload::query()->where(function (Builder $query) {
            $query->where(fn (Builder $reserved) => $reserved
                ->where('status', BuddyArtifactUpload::STATUS_RESERVED)
                ->where('expires_at', '<', now()))
                ->orWhere(fn (Builder $claimed) => $claimed
                    ->where('status', BuddyArtifactUpload::STATUS_UPLOADED)
                    ->where('updated_at', '<', now()->subHour()));
        });
    }

    private function pastRetention(): Builder
    {
        return BuddyArtifact::query()
            ->whereNotNull('storage_status')
            ->whereNull('deleted_at')
            ->where('retention_until', '<', now());
    }

    private function unpurged(): Builder
    {
        return BuddyArtifact::query()
            ->where('storage_status', ArtifactStorageService::STATUS_DELETED)
            ->where('deleted_at', '<', now()->subDay());
    }

    /*
     * A signed upload URL outlives finalization by up to its TTL, so a
     * re-PUT can leave an orphan at a staging key nothing references.
     */
    private function staleStaging(): Builder
    {
        return BuddyArtifactUpload::query()
            ->whereIn('status', [BuddyArtifactUpload::STATUS_FINALIZED, BuddyArtifactUpload::STATUS_FAILED])
            ->whereBetween('expires_at', [now()->subDay(), now()]);
    }
}
