<?php

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BuddyRun extends Model
{
    protected $fillable = [
        'buddy_task_id',
        'run_number',
        'status',
        'model_used',
        'token_usage',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'token_usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }

    public function recommendation(): HasOne
    {
        return $this->hasOne(BuddyRecommendation::class);
    }
}
