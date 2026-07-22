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
        'run_type',
        'status',
        'model_used',
        'provider',
        'prompt_hash',
        'prompt_modules',
        'langsmith_run_id',
        'error_class',
        'token_usage',
        'cost',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'prompt_modules' => 'array',
            'token_usage' => 'array',
            'cost' => 'array',
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
