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
    ];

    protected function casts(): array
    {
        return [
            'type' => ArtifactType::class,
            'metadata' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }
}
