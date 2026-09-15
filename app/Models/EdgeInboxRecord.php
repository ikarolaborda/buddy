<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdgeInboxRecord extends Model
{
    protected $fillable = [
        'consumer',
        'event_id',
        'payload_hash',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
