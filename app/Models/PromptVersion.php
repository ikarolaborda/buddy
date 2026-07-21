<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptVersion extends Model
{
    protected $fillable = [
        'agent',
        'content_hash',
        'module_ids',
        'module_hashes',
    ];

    protected function casts(): array
    {
        return [
            'module_ids' => 'array',
            'module_hashes' => 'array',
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(PromptDeployment::class);
    }
}
