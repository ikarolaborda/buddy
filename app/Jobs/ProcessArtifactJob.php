<?php

namespace App\Jobs;

use App\Contracts\ArtifactObjectStore;
use App\Models\BuddyArtifact;
use App\Services\Artifacts\ArtifactProcessingException;
use App\Services\Artifacts\ArtifactStorageService;
use App\Services\Artifacts\ArtifactTextExtractor;
use App\Services\Edge\TaskProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/*
 * Bounded post-finalization processing (plan §9). The logical key is
 * (artifact, sha256, processor version): a repeat for the same key is a
 * no-op, and two tries are the whole budget so a poison object is marked
 * failed instead of being parsed forever.
 */
#[Tries(2)]
#[Timeout(120)]
class ProcessArtifactJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const VERSION = 'v1';

    public int $uniqueFor = 300;

    public function __construct(
        public int $artifactId,
        public string $sha256,
    ) {
        $this->onQueue((string) config('buddy.queues.lanes.fast'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->artifactId.':'.$this->sha256.':'.self::VERSION;
    }

    public function handle(
        ArtifactObjectStore $store,
        ArtifactTextExtractor $extractor,
        TaskProgressService $progress,
    ): void {
        $artifact = BuddyArtifact::query()->find($this->artifactId);

        if ($artifact === null || ! $this->applies($artifact)) {
            return;
        }

        try {
            $content = $extractor->extract($store, $artifact);
        } catch (ArtifactProcessingException $e) {
            $this->finish($artifact, ArtifactStorageService::PROCESSING_FAILED, $e->reason, $progress);

            return;
        }

        $this->finish($artifact, ArtifactStorageService::PROCESSING_COMPLETED, null, $progress, $content);
    }

    public function failed(?Throwable $exception): void
    {
        $artifact = BuddyArtifact::query()->find($this->artifactId);

        if ($artifact === null || ! $this->applies($artifact)) {
            return;
        }

        $this->finish($artifact, ArtifactStorageService::PROCESSING_FAILED, 'job_failed', app(TaskProgressService::class));
    }

    private function applies(BuddyArtifact $artifact): bool
    {
        if ($artifact->deleted_at !== null || $artifact->storage_status !== ArtifactStorageService::STATUS_READY) {
            return false;
        }

        if ($artifact->sha256 !== $this->sha256) {
            return false;
        }

        $settled = [ArtifactStorageService::PROCESSING_COMPLETED, ArtifactStorageService::PROCESSING_FAILED];

        return ! ($artifact->processor_version === self::VERSION && in_array($artifact->processing_status, $settled, true));
    }

    private function finish(
        BuddyArtifact $artifact,
        string $status,
        ?string $error,
        TaskProgressService $progress,
        ?string $content = null,
    ): void {
        DB::transaction(function () use ($artifact, $status, $error, $progress, $content) {
            // A tombstone written while parsing wins: derived text never
            // reappears on a deleted artifact.
            $updated = BuddyArtifact::query()
                ->whereKey($artifact->id)
                ->whereNull('deleted_at')
                ->where('sha256', $this->sha256)
                ->update([
                    'content' => $content ?? '[processing failed: '.$error.']',
                    'processing_status' => $status,
                    'processor_version' => self::VERSION,
                ]);

            if ($updated !== 1) {
                return;
            }

            $progress->record($artifact->task, TaskProgressService::TYPE_ARTIFACT_PROCESSED, [
                'artifact_id' => $artifact->id,
                'processing_status' => $status,
                'processor_version' => self::VERSION,
                'content_hash' => $this->sha256,
                'error' => $error,
            ]);
        });
    }
}
