<?php

namespace App\Models;

use App\Enums\ApiScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiKey extends Model
{
    protected $fillable = [
        'api_client_id',
        'public_id',
        'secret_digest',
        'scopes',
        'rate_limit_per_minute',
        'max_concurrency',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApiKeyEvent::class);
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->client->active;
    }

    public function hasScope(ApiScope $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array($scope->value, $scopes, true)
            || in_array(ApiScope::Admin->value, $scopes, true);
    }
}
