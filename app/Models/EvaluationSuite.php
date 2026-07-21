<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationSuite extends Model
{
    protected $fillable = [
        'name',
        'kind',
        'cases',
        'frozen',
    ];

    protected function casts(): array
    {
        return [
            'cases' => 'array',
            'frozen' => 'boolean',
        ];
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(EvaluationRun::class);
    }
}
