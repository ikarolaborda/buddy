<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentProfile extends Model
{
    protected $fillable = [
        'name',
        'version',
        'provider',
        'model',
        'timeout',
        'max_steps',
        'temperature',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'timeout' => 'integer',
            'max_steps' => 'integer',
            'temperature' => 'float',
            'active' => 'boolean',
        ];
    }
}
