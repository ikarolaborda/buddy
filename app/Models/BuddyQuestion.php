<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyQuestion extends Model
{
    protected $fillable = [
        'buddy_task_id',
        'question',
        'context',
        'answered',
        'answer',
    ];

    protected function casts(): array
    {
        return [
            'answered' => 'boolean',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }
}
