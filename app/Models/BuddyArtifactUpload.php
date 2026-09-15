<?php

namespace App\Models;

use App\Enums\ArtifactType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyArtifactUpload extends Model
{
    use HasUlids;

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const ACTIVE_STATUSES = [self::STATUS_RESERVED, self::STATUS_UPLOADED];

    protected $fillable = [
        'buddy_task_id',
        'api_client_id',
        'artifact_type',
        'declared_size',
        'media_type',
        'staging_key',
        'status',
        'expires_at',
        'finalized_at',
        'buddy_artifact_id',
    ];

    protected function casts(): array
    {
        return [
            'artifact_type' => ArtifactType::class,
            'declared_size' => 'integer',
            'expires_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(BuddyArtifact::class, 'buddy_artifact_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /*
     * Bytes are charged to the day the reservation was made, so a finalize
     * after midnight settles the same quota row it reserved against.
     */
    public function quotaDay(): string
    {
        return ($this->created_at ?? now())->toDateString();
    }
}
