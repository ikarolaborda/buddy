<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyArtifactQuota extends Model
{
    protected $fillable = [
        'api_client_id',
        'day',
        'reserved_bytes',
        'committed_bytes',
    ];

    protected function casts(): array
    {
        return [
            'reserved_bytes' => 'integer',
            'committed_bytes' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
