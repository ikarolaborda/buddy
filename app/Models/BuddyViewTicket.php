<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyViewTicket extends Model
{
    use HasUlids;

    protected $fillable = [
        'buddy_task_id',
        'api_client_id',
        'ticket_hash',
        'scope',
        'generation',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
