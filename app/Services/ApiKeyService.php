<?php

namespace App\Services;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\ApiKey;
use Illuminate\Support\Str;

class ApiKeyService
{
    protected const PREFIX = 'bdy_live_';

    /**
     * @param  array<int, ApiScope>  $scopes
     * @return array{key: ApiKey, plaintext: string}
     */
    public function issue(
        ApiClient $client,
        array $scopes,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $publicId = Str::lower(Str::random(16));
        $secret = bin2hex(random_bytes(32));

        $key = ApiKey::create([
            'api_client_id' => $client->id,
            'public_id' => $publicId,
            'secret_digest' => $this->digest($secret),
            'scopes' => array_map(fn (ApiScope $scope) => $scope->value, $scopes),
            'expires_at' => $expiresAt,
        ]);

        $key->events()->create(['event' => 'issued']);

        return [
            'key' => $key,
            'plaintext' => self::PREFIX.$publicId.'_'.$secret,
        ];
    }

    public function verify(?string $bearer): ?ApiKey
    {
        if ($bearer === null || ! str_starts_with($bearer, self::PREFIX)) {
            return null;
        }

        $rest = substr($bearer, strlen(self::PREFIX));
        $separator = strpos($rest, '_');

        if ($separator === false) {
            return null;
        }

        $publicId = substr($rest, 0, $separator);
        $secret = substr($rest, $separator + 1);

        if ($publicId === '' || $secret === '') {
            return null;
        }

        $key = ApiKey::query()
            ->with('client')
            ->where('public_id', $publicId)
            ->first();

        if ($key === null) {
            return null;
        }

        if (! hash_equals($key->secret_digest, $this->digest($secret))) {
            return null;
        }

        if (! $key->isUsable()) {
            return null;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $key;
    }

    public function revoke(ApiKey $key, ?string $reason = null): void
    {
        $key->update(['revoked_at' => now()]);
        $key->events()->create([
            'event' => 'revoked',
            'context' => $reason !== null ? ['reason' => $reason] : null,
        ]);
    }

    protected function digest(string $secret): string
    {
        $pepper = (string) config('buddy.api.key_pepper');

        return hash_hmac('sha256', $secret, $pepper);
    }
}
