<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyViewSession extends Model
{
    use HasUlids;

    protected $fillable = [
        'buddy_view_ticket_id',
        'buddy_task_id',
        'api_client_id',
        'session_hash',
        'scope',
        'origin',
        'expires_at',
        'hard_expires_at',
        'revoked_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'hard_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
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

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at->isPast() || $this->hard_expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
