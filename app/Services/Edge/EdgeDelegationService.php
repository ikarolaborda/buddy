<?php

namespace App\Services\Edge;

use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\BuddyTask;
use Illuminate\Support\Str;

/*
 * Delegations let the Cloudflare supervisor act for the task owner without
 * ever holding a client API key. A delegation is an HMAC token minted by
 * Azure that binds delegation id, client, task, generation, scopes and expiry.
 * Verification recomputes everything from the current database state:
 * ownership, client status, scope held by a currently usable key, and the
 * task generation. Revocation therefore fails closed even for a valid token.
 */
final class EdgeDelegationService
{
    public const PREFIX = 'bdg1.';

    /**
     * @param  array<int, ApiScope>  $scopes
     */
    public function mint(BuddyTask $task, array $scopes, ?int $ttlSeconds = null): string
    {
        // Read the generation from the database: a freshly created model
        // does not carry column defaults, and a delegation minted for
        // generation 0 would never verify against the stored row.
        $generation = (int) BuddyTask::query()->whereKey($task->id)->value('generation');

        $payload = [
            'id' => (string) Str::ulid(),
            'client' => (int) $task->api_client_id,
            'task' => $task->ulid,
            'gen' => $generation,
            'scopes' => array_values(array_map(fn (ApiScope $scope) => $scope->value, $scopes)),
            'exp' => now()->addSeconds($ttlSeconds ?? (int) config('buddy.edge.delegation_ttl', 172800))->getTimestamp(),
        ];

        $encoded = $this->encode((string) json_encode($payload));

        return self::PREFIX.$encoded.'.'.$this->encode($this->sign($encoded));
    }

    /**
     * @return array{id: string, client: int, task: string, gen: int, scopes: array<int, string>, exp: int}|null
     */
    public function verify(string $token, BuddyTask $task, ApiScope $required): ?array
    {
        if (! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $parts = explode('.', substr($token, strlen(self::PREFIX)));

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        if (! hash_equals($this->encode($this->sign($encoded)), $signature)) {
            return null;
        }

        $payload = json_decode((string) $this->decode($encoded), true);

        if (! is_array($payload)) {
            return null;
        }

        if (($payload['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $generation = (int) BuddyTask::query()->whereKey($task->id)->value('generation');

        if (($payload['task'] ?? null) !== $task->ulid || (int) ($payload['gen'] ?? 0) !== $generation) {
            return null;
        }

        if ($task->api_client_id === null || (int) ($payload['client'] ?? 0) !== (int) $task->api_client_id) {
            return null;
        }

        if (! in_array($required->value, $payload['scopes'] ?? [], true)) {
            return null;
        }

        if (! $this->clientCurrentlyHolds($task, $required)) {
            return null;
        }

        return $payload;
    }

    /*
     * The delegation only ever narrows what the owner can do today. If every
     * key carrying the scope was revoked or expired, the delegation is dead
     * with them; a cached projection cannot resurrect it.
     */
    public function clientCurrentlyHolds(BuddyTask $task, ApiScope $scope): bool
    {
        $client = $task->client;

        if ($client === null || ! $client->active) {
            return false;
        }

        return ApiKey::query()
            ->where('api_client_id', $client->id)
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()
            ->contains(fn (ApiKey $key) => $key->hasScope($scope));
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, $this->secret(), true);
    }

    private function secret(): string
    {
        $configured = (string) config('buddy.edge.delegation_secret');

        if ($configured !== '') {
            return $configured;
        }

        return hash_hmac('sha256', 'buddy-edge-delegation', (string) config('app.key'));
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
