<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactObjectStore;
use App\Enums\ArtifactType;
use App\Jobs\ProcessArtifactJob;
use App\Jobs\PurgeArtifactObjectJob;
use App\Models\ApiClient;
use App\Models\BuddyArtifact;
use App\Models\BuddyArtifactQuota;
use App\Models\BuddyArtifactUpload;
use App\Models\BuddyTask;
use App\Services\Edge\TaskProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/*
 * Reservation, finalization, download and deletion for R2-backed artifacts
 * (plan §9). PostgreSQL stays the authority: an object key never proves
 * ownership, a client can only ever write a staging key, and every byte the
 * final key holds was counted, sniffed and hashed here first.
 */
final class ArtifactStorageService
{
    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted';

    public const STATUS_PURGED = 'purged';

    public const PROCESSING_PENDING = 'pending';

    public const PROCESSING_COMPLETED = 'completed';

    public const PROCESSING_FAILED = 'failed';

    public const PENDING_CONTENT = '[stored object; processing pending]';

    public const DELETED_CONTENT = '[artifact deleted]';

    public const SUMMARY_CHARS = 2000;

    private const READ_CHUNK = 65536;

    public function __construct(
        private ArtifactObjectStore $store,
        private TaskProgressService $progress,
    ) {}

    /**
     * @return array{upload: BuddyArtifactUpload, url: string, headers: array<string, string>}
     */
    public function reserve(BuddyTask $task, ApiClient $client, ArtifactType $type, int $declaredSize, string $mediaType): array
    {
        $normalized = ArtifactMediaTypes::normalize($mediaType);

        if ($normalized === null) {
            throw ArtifactStorageException::unsupportedMediaType();
        }

        $uploadCap = $this->quota('upload_bytes');

        if ($declaredSize < 1 || $declaredSize > $uploadCap) {
            throw ArtifactStorageException::uploadTooLarge($uploadCap);
        }

        $upload = DB::transaction(function () use ($task, $client, $type, $declaredSize, $normalized) {
            $quota = $this->lockQuota($client->id, now()->toDateString());

            $active = BuddyArtifactUpload::query()
                ->where('api_client_id', $client->id)
                ->whereIn('status', BuddyArtifactUpload::ACTIVE_STATUSES)
                ->count();
            $activeCap = $this->quota('active_reservations');

            if ($active >= $activeCap) {
                throw ArtifactStorageException::quotaExhausted('active_reservations', $activeCap);
            }

            // Per-task accounting is serialized on the task row so two
            // concurrent reservations cannot both squeeze under the cap.
            BuddyTask::query()->whereKey($task->id)->lockForUpdate()->first();
            $taskCap = $this->quota('task_bytes');

            if ($this->taskBytes($task) + $declaredSize > $taskCap) {
                throw ArtifactStorageException::quotaExhausted('task', $taskCap);
            }

            $dailyCap = $this->quota('client_daily_bytes');

            if ($quota->reserved_bytes + $quota->committed_bytes + $declaredSize > $dailyCap) {
                throw ArtifactStorageException::quotaExhausted('client_daily', $dailyCap);
            }

            $quota->forceFill(['reserved_bytes' => $quota->reserved_bytes + $declaredSize])->save();

            $upload = new BuddyArtifactUpload([
                'buddy_task_id' => $task->id,
                'api_client_id' => $client->id,
                'artifact_type' => $type,
                'declared_size' => $declaredSize,
                'media_type' => $normalized,
                'status' => BuddyArtifactUpload::STATUS_RESERVED,
                'expires_at' => now()->addSeconds($this->retention('upload_url_seconds')),
            ]);
            $upload->id = (string) Str::ulid();
            $upload->staging_key = sprintf('clients/%d/tasks/%s/staging/%s', $client->id, $task->ulid, $upload->id);
            $upload->save();

            return $upload;
        });

        try {
            $signed = $this->store->presignUpload(
                $upload->staging_key,
                $this->retention('upload_url_seconds'),
                $normalized,
                $declaredSize,
            );
        } catch (Throwable $e) {
            $this->expire($upload, BuddyArtifactUpload::STATUS_FAILED);

            throw $this->unavailable('presign upload', $e);
        }

        return ['upload' => $upload, 'url' => $signed['url'], 'headers' => $signed['headers']];
    }

    /*
     * Idempotent: a finalized reservation returns its artifact. The status
     * flip reserved -> uploaded is the only arbiter between concurrent
     * callers, so exactly one of them verifies and copies.
     */
    public function finalize(BuddyArtifactUpload $upload): BuddyArtifact
    {
        $upload->refresh();

        if ($upload->status === BuddyArtifactUpload::STATUS_FINALIZED) {
            return $upload->artifact ?? throw ArtifactStorageException::notFound();
        }

        $claimed = BuddyArtifactUpload::query()
            ->whereKey($upload->id)
            ->where('status', BuddyArtifactUpload::STATUS_RESERVED)
            ->update(['status' => BuddyArtifactUpload::STATUS_UPLOADED, 'updated_at' => now()]);

        if ($claimed !== 1) {
            $upload->refresh();

            return match ($upload->status) {
                BuddyArtifactUpload::STATUS_FINALIZED => $upload->artifact ?? throw ArtifactStorageException::notFound(),
                BuddyArtifactUpload::STATUS_UPLOADED => throw ArtifactStorageException::processingPending(),
                BuddyArtifactUpload::STATUS_EXPIRED => throw ArtifactStorageException::reservationExpired(),
                default => throw ArtifactStorageException::uploadFailed('previous_failure'),
            };
        }

        $upload->status = BuddyArtifactUpload::STATUS_UPLOADED;

        try {
            $head = $this->store->head($upload->staging_key);
        } catch (Throwable $e) {
            $this->unclaim($upload);

            throw $this->unavailable('head staging object', $e);
        }

        if ($head === null) {
            $this->unclaim($upload);

            throw ArtifactStorageException::objectMissing();
        }

        $uploadCap = $this->quota('upload_bytes');

        if ($head['size'] > $uploadCap) {
            $this->expire($upload, BuddyArtifactUpload::STATUS_FAILED);

            throw ArtifactStorageException::uploadTooLarge($uploadCap);
        }

        if ($head['size'] !== $upload->declared_size) {
            $this->expire($upload, BuddyArtifactUpload::STATUS_FAILED);

            throw ArtifactStorageException::uploadFailed('size_mismatch');
        }

        try {
            $verified = $this->verify($upload->staging_key, $upload->declared_size);
        } catch (Throwable $e) {
            $this->unclaim($upload);

            throw $this->unavailable('read staging object', $e);
        }

        if ($verified['size'] !== $upload->declared_size) {
            $this->expire($upload, BuddyArtifactUpload::STATUS_FAILED);

            throw ArtifactStorageException::uploadFailed('size_mismatch');
        }

        if (! ArtifactMediaTypes::matches($upload->media_type, $verified['leading'], $verified['size'])) {
            $this->expire($upload, BuddyArtifactUpload::STATUS_FAILED);

            throw ArtifactStorageException::uploadFailed('media_type_mismatch');
        }

        $task = $upload->task;
        $finalKey = sprintf('clients/%d/tasks/%s/artifacts/%s', $upload->api_client_id, $task->ulid, (string) Str::ulid());

        try {
            if (! $this->store->copy($upload->staging_key, $finalKey)) {
                throw new RuntimeException('copy returned false');
            }
        } catch (Throwable $e) {
            $this->unclaim($upload);

            throw $this->unavailable('copy to final key', $e);
        }

        try {
            $artifact = DB::transaction(function () use ($upload, $task, $finalKey, $verified) {
                $artifact = BuddyArtifact::create([
                    'buddy_task_id' => $task->id,
                    'type' => $upload->artifact_type,
                    'content' => self::PENDING_CONTENT,
                    'metadata' => ['upload_id' => $upload->id],
                    'object_key' => $finalKey,
                    'size_bytes' => $verified['size'],
                    'media_type' => $upload->media_type,
                    'sha256' => $verified['sha256'],
                    'storage_status' => self::STATUS_READY,
                    'processing_status' => self::PROCESSING_PENDING,
                    'retention_until' => now()->addDays($this->retention('artifact_days')),
                ]);

                $upload->forceFill([
                    'status' => BuddyArtifactUpload::STATUS_FINALIZED,
                    'finalized_at' => now(),
                    'buddy_artifact_id' => $artifact->id,
                ])->save();

                $quota = $this->lockQuota($upload->api_client_id, $upload->quotaDay());
                $quota->forceFill([
                    'reserved_bytes' => max(0, $quota->reserved_bytes - $upload->declared_size),
                    'committed_bytes' => $quota->committed_bytes + $verified['size'],
                ])->save();

                $this->progress->record($task, TaskProgressService::TYPE_ARTIFACT_AVAILABLE, [
                    'artifact_id' => $artifact->id,
                    'media_type' => $artifact->media_type,
                    'size_bytes' => $artifact->size_bytes,
                    'content_hash' => $artifact->sha256,
                ]);

                return $artifact;
            });
        } catch (Throwable $e) {
            // Nothing references the copy, so it goes; the staging object
            // stays and the reservation reopens for a retry.
            $this->deleteQuietly($finalKey);
            $this->unclaim($upload);

            throw $e;
        }

        $this->deleteQuietly($upload->staging_key);

        ProcessArtifactJob::dispatch($artifact->id, (string) $artifact->sha256);

        return $artifact;
    }

    /**
     * @return array<string, mixed>
     */
    public function receipt(BuddyTask $task, BuddyArtifact $artifact): array
    {
        return [
            'artifact_id' => $artifact->id,
            'task_id' => $task->ulid,
            'type' => $artifact->type->value,
            'media_type' => $artifact->media_type,
            'size_bytes' => $artifact->size_bytes,
            'content_hash' => $artifact->sha256,
            'storage_status' => $artifact->storage_status,
            'retention_until' => $artifact->retention_until?->toISOString(),
        ];
    }

    /**
     * @return array{url: string, expires_at: Carbon, filename: string, media_type: string}
     */
    public function downloadUrl(BuddyArtifact $artifact): array
    {
        if (! $this->isAvailable($artifact)) {
            throw ArtifactStorageException::notFound();
        }

        $seconds = $this->retention('download_url_seconds');
        $mediaType = (string) $artifact->media_type;
        $filename = sprintf('artifact-%d.%s', $artifact->id, ArtifactMediaTypes::extension($mediaType));

        try {
            $url = $this->store->presignDownload((string) $artifact->object_key, $seconds, $filename, $mediaType);
        } catch (Throwable $e) {
            throw $this->unavailable('presign download', $e);
        }

        return [
            'url' => $url,
            'expires_at' => now()->addSeconds($seconds),
            'filename' => $filename,
            'media_type' => $mediaType,
        ];
    }

    /*
     * The tombstone is immediate and authoritative; the object goes
     * asynchronously and access never comes back, purge or no purge.
     */
    public function delete(BuddyArtifact $artifact): BuddyArtifact
    {
        if (! $this->hasStorage($artifact)) {
            throw ArtifactStorageException::notFound();
        }

        if ($artifact->deleted_at !== null) {
            return $artifact;
        }

        $artifact->forceFill([
            'deleted_at' => now(),
            'storage_status' => self::STATUS_DELETED,
            'content' => self::DELETED_CONTENT,
        ])->save();

        PurgeArtifactObjectJob::dispatch($artifact->id);

        return $artifact;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(BuddyTask $task, BuddyArtifact $artifact, bool $metadataOnly): array
    {
        if (! $this->hasStorage($artifact) || $artifact->deleted_at !== null) {
            throw ArtifactStorageException::notFound();
        }

        if (! $metadataOnly && $artifact->processing_status === self::PROCESSING_PENDING) {
            throw ArtifactStorageException::processingPending();
        }

        $summary = [
            'artifact_id' => $artifact->id,
            'task_id' => $task->ulid,
            'type' => $artifact->type->value,
            'media_type' => $artifact->media_type,
            'size_bytes' => $artifact->size_bytes,
            'content_hash' => $artifact->sha256,
            'processor_version' => $artifact->processor_version,
            'processing_status' => $artifact->processing_status,
            'storage_status' => $artifact->storage_status,
        ];

        if (! $metadataOnly) {
            $summary['summary'] = mb_substr((string) $artifact->content, 0, self::SUMMARY_CHARS);
        }

        return $summary;
    }

    /*
     * Terminal for a reservation that never became an artifact: the bytes go
     * back to the quota and the staging object is dropped. Safe to repeat.
     */
    public function expire(BuddyArtifactUpload $upload, string $status = BuddyArtifactUpload::STATUS_EXPIRED): bool
    {
        $released = DB::transaction(function () use ($upload, $status) {
            $current = BuddyArtifactUpload::query()->whereKey($upload->id)->lockForUpdate()->first();

            if ($current === null || ! $current->isActive()) {
                return false;
            }

            $current->forceFill(['status' => $status])->save();

            $quota = $this->lockQuota($current->api_client_id, $current->quotaDay());
            $quota->forceFill(['reserved_bytes' => max(0, $quota->reserved_bytes - $current->declared_size)])->save();

            return true;
        });

        if ($released) {
            $upload->status = $status;
            $this->deleteQuietly($upload->staging_key);
        }

        return $released;
    }

    public function hasStorage(BuddyArtifact $artifact): bool
    {
        return $artifact->storage_status !== null && $artifact->object_key !== null;
    }

    public function isAvailable(BuddyArtifact $artifact): bool
    {
        return $this->hasStorage($artifact)
            && $artifact->storage_status === self::STATUS_READY
            && $artifact->deleted_at === null;
    }

    public function deleteQuietly(string $key): void
    {
        try {
            if (! $this->store->delete($key)) {
                Log::warning('Artifact object delete deferred to cleanup', ['key' => $key]);
            }
        } catch (Throwable $e) {
            Log::warning('Artifact object delete deferred to cleanup', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Streams the staging object once: the hash, the byte count and the
     * sniffable prefix all come from the same read, never from a client claim.
     *
     * @return array{sha256: string, size: int, leading: string}
     */
    private function verify(string $key, int $expectedSize): array
    {
        $stream = $this->store->readStream($key);

        if (! is_resource($stream)) {
            throw new RuntimeException('staging object is not readable');
        }

        $hash = hash_init('sha256');
        $size = 0;
        $leading = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::READ_CHUNK);

                if ($chunk === false) {
                    throw new RuntimeException('staging object read failed');
                }

                if ($chunk === '') {
                    break;
                }

                hash_update($hash, $chunk);
                $size += strlen($chunk);

                if (strlen($leading) < ArtifactMediaTypes::SNIFF_BYTES) {
                    $leading .= substr($chunk, 0, ArtifactMediaTypes::SNIFF_BYTES - strlen($leading));
                }

                // Never stream past what was declared; a mismatch is a failure either way.
                if ($size > $expectedSize) {
                    break;
                }
            }
        } finally {
            fclose($stream);
        }

        return ['sha256' => hash_final($hash), 'size' => $size, 'leading' => $leading];
    }

    private function unclaim(BuddyArtifactUpload $upload): void
    {
        BuddyArtifactUpload::query()
            ->whereKey($upload->id)
            ->where('status', BuddyArtifactUpload::STATUS_UPLOADED)
            ->update(['status' => BuddyArtifactUpload::STATUS_RESERVED, 'updated_at' => now()]);

        $upload->status = BuddyArtifactUpload::STATUS_RESERVED;
    }

    private function taskBytes(BuddyTask $task): int
    {
        $ready = (int) BuddyArtifact::query()
            ->where('buddy_task_id', $task->id)
            ->where('storage_status', self::STATUS_READY)
            ->whereNull('deleted_at')
            ->sum('size_bytes');

        $pending = (int) BuddyArtifactUpload::query()
            ->where('buddy_task_id', $task->id)
            ->whereIn('status', BuddyArtifactUpload::ACTIVE_STATUSES)
            ->sum('declared_size');

        return $ready + $pending;
    }

    private function lockQuota(int $clientId, string $day): BuddyArtifactQuota
    {
        $quota = $this->quotaQuery($clientId, $day)->lockForUpdate()->first();

        if ($quota !== null) {
            return $quota;
        }

        try {
            // A savepoint keeps the outer transaction usable on PostgreSQL if
            // a concurrent reservation inserted the row first.
            DB::transaction(fn () => BuddyArtifactQuota::create(['api_client_id' => $clientId, 'day' => $day]));
        } catch (UniqueConstraintViolationException) {
        }

        return $this->quotaQuery($clientId, $day)->lockForUpdate()->firstOrFail();
    }

    private function quotaQuery(int $clientId, string $day): Builder
    {
        return BuddyArtifactQuota::query()->where('api_client_id', $clientId)->where('day', $day);
    }

    private function unavailable(string $operation, Throwable $e): ArtifactStorageException
    {
        Log::warning('Artifact object store unavailable', ['operation' => $operation, 'error' => $e->getMessage()]);

        return ArtifactStorageException::dependencyUnavailable();
    }

    private function quota(string $key): int
    {
        return (int) config('buddy.edge.quotas.'.$key);
    }

    private function retention(string $key): int
    {
        return (int) config('buddy.edge.retention.'.$key);
    }
}
