<?php

namespace App\Models;

use App\Enums\Confidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyRecommendation extends Model
{
    protected $fillable = [
        'buddy_run_id',
        'accepted',
        'confidence',
        'summary',
        'recommended_plan',
        'rejected_reasons',
        'required_followups',
        'risks',
        'next_actions',
        'memory_hits',
    ];

    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'confidence' => Confidence::class,
            'recommended_plan' => 'array',
            'rejected_reasons' => 'array',
            'required_followups' => 'array',
            'risks' => 'array',
            'next_actions' => 'array',
            'memory_hits' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BuddyRun::class, 'buddy_run_id');
    }

    public function task(): BuddyTask
    {
        return $this->run->task;
    }
}
