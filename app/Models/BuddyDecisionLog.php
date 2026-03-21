<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyDecisionLog extends Model
{
    protected $fillable = [
        'buddy_task_id',
        'buddy_run_id',
        'decision_type',
        'rationale',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BuddyRun::class, 'buddy_run_id');
    }
}
