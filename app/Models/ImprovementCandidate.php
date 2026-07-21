<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImprovementCandidate extends Model
{
    protected $fillable = [
        'kind',
        'parent_version',
        'rationale',
        'expected_effect',
        'payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(EvaluationRun::class);
    }

    public function promotionDecisions(): HasMany
    {
        return $this->hasMany(PromotionDecision::class);
    }
}
