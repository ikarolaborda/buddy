<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyMemoryReference extends Model
{
    protected $fillable = [
        'buddy_task_id',
        'qdrant_point_id',
        'similarity_score',
        'memory_summary',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'similarity_score' => 'float',
            'tags' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }
}
