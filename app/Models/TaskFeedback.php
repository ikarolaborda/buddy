<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFeedback extends Model
{
    protected $table = 'task_feedback';

    protected $fillable = [
        'buddy_task_id',
        'outcome',
        'score',
        'comment',
        'source',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }
}
