<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionDecision extends Model
{
    protected $fillable = [
        'improvement_candidate_id',
        'decided_by',
        'approved',
        'rationale',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'approved' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ImprovementCandidate::class, 'improvement_candidate_id');
    }
}
