<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationRun extends Model
{
    protected $fillable = [
        'improvement_candidate_id',
        'evaluation_suite_id',
        'baseline_metrics',
        'candidate_metrics',
        'passed',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'baseline_metrics' => 'array',
            'candidate_metrics' => 'array',
            'passed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ImprovementCandidate::class, 'improvement_candidate_id');
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(EvaluationSuite::class, 'evaluation_suite_id');
    }
}
