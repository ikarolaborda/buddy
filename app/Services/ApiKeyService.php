<?php

namespace App\Services;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\ApiKey;
use Illuminate\Support\Facades\Cache;
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

        $key = $this->lookup($publicId);

        if ($key === null) {
            return null;
        }

        if (! hash_equals($key->secret_digest, $this->digest($secret))) {
            return null;
        }

        if (! $key->isUsable()) {
            return null;
        }

        $this->touchLastUsed($key, $publicId);

        return $key;
    }

    public function revoke(ApiKey $key, ?string $reason = null): void
    {
        $key->update(['revoked_at' => now()]);
        $key->events()->create([
            'event' => 'revoked',
            'context' => $reason !== null ? ['reason' => $reason] : null,
        ]);

        Cache::forget(self::cacheKey($key->public_id));
    }

    /*
     * Verified keys are cached for a short TTL so the hot path skips the
     * key+client queries. Revocation through revoke() invalidates
     * immediately; a direct-DB revocation or client deactivation lingers
     * for at most the TTL (accepted, ADR 0008). Expiry never lingers:
     * isUsable() re-checks expires_at on every request. Unknown public
     * ids are not cached, so the 401 path always hits the database.
     */
    protected function lookup(string $publicId): ?ApiKey
    {
        $ttl = $this->cacheTtl();

        if ($ttl <= 0) {
            return $this->queryKey($publicId);
        }

        $cached = Cache::get(self::cacheKey($publicId));

        if (is_array($cached)) {
            return $this->hydrate($cached);
        }

        $key = $this->queryKey($publicId);

        if ($key !== null) {
            Cache::put(self::cacheKey($publicId), $this->dehydrate($key), $ttl);
        }

        return $key;
    }

    protected function queryKey(string $publicId): ?ApiKey
    {
        return ApiKey::query()
            ->with('client')
            ->where('public_id', $publicId)
            ->first();
    }

    /*
     * Cache stores unserialize with an allowed_classes restriction, so
     * Eloquent models come back as __PHP_Incomplete_Class. Plain
     * attribute arrays survive every store; models are rebuilt on read.
     *
     * @return array{key: array<string, mixed>, client: array<string, mixed>|null}
     */
    protected function dehydrate(ApiKey $key): array
    {
        return [
            'key' => $key->getAttributes(),
            'client' => $key->client?->getAttributes(),
        ];
    }

    /**
     * @param  array{key: array<string, mixed>, client: array<string, mixed>|null}  $cached
     */
    protected function hydrate(array $cached): ApiKey
    {
        $key = (new ApiKey)->newFromBuilder($cached['key']);

        $key->setRelation(
            'client',
            $cached['client'] !== null ? (new ApiClient)->newFromBuilder($cached['client']) : null,
        );

        return $key;
    }

    /*
     * last_used_at is write-only telemetry; one UPDATE per key per TTL
     * window is enough and removes a per-request write from the hot path.
     */
    protected function touchLastUsed(ApiKey $key, string $publicId): void
    {
        $ttl = max($this->cacheTtl(), 60);

        if ($key->last_used_at !== null && $key->last_used_at->gt(now()->subSeconds($ttl))) {
            return;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        if ($this->cacheTtl() > 0) {
            Cache::put(self::cacheKey($publicId), $this->dehydrate($key), $this->cacheTtl());
        }
    }

    protected function cacheTtl(): int
    {
        return (int) config('buddy.api.key_cache_ttl', 60);
    }

    protected static function cacheKey(string $publicId): string
    {
        return 'buddy:apikey:'.$publicId;
    }

    protected function digest(string $secret): string
    {
        $pepper = (string) config('buddy.api.key_pepper');

        return hash_hmac('sha256', $secret, $pepper);
    }
}
