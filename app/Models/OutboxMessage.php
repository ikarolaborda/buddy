<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboxMessage extends Model
{
    protected $fillable = [
        'topic',
        'message_key',
        'payload',
        'attempts',
        'last_error',
        'available_at',
        'processed_at',
        'destinations',
        'quarantined_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'destinations' => 'array',
            'quarantined_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(OutboxDelivery::class, 'outbox_message_id');
    }
}
