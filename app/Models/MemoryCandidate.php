<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryCandidate extends Model
{
    protected $fillable = [
        'buddy_task_id',
        'status',
        'problem',
        'solution',
        'impact',
        'tags',
        'evidence',
        'technology_versions',
        'source_references',
        'rejection_reason',
        'promoted_memory_id',
        'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'evidence' => 'array',
            'technology_versions' => 'array',
            'source_references' => 'array',
            'promoted_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }
}
