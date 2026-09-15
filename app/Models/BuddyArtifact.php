<?php

namespace App\Models;

use App\Enums\ArtifactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyArtifact extends Model
{
    protected $fillable = [
        'buddy_task_id',
        'type',
        'content',
        'metadata',
        'object_key',
        'size_bytes',
        'media_type',
        'sha256',
        'storage_status',
        'original_artifact_id',
        'processor_version',
        'processing_status',
        'retention_until',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ArtifactType::class,
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'retention_until' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }
}
