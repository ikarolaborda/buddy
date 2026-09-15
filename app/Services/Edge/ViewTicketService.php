<?php

namespace App\Services\Edge;

use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Models\BuddyViewSession;
use App\Models\BuddyViewTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Single-use, single-task browser access (plan §5, identity). The API mints a
 * short-lived ticket for a task it has already authorised; the Worker
 * exchanges it exactly once for an opaque session whose hash is stored here.
 * Only hashes are persisted, so a database read never yields a usable secret.
 */
final class ViewTicketService
{
    public const SCOPE_VIEW = 'task:view';

    /**
     * @return array{ticket: string, expires_at: Carbon, model: BuddyViewTicket}
     */
    public function mint(BuddyTask $task, ApiClient $client, string $scope = self::SCOPE_VIEW): array
    {
        $plaintext = 'bvt_'.bin2hex(random_bytes(32));
        $expiresAt = now()->addSeconds((int) config('buddy.edge.view_ticket_ttl', 60));

        $ticket = BuddyViewTicket::create([
            'buddy_task_id' => $task->id,
            'api_client_id' => $client->id,
            'ticket_hash' => $this->hash($plaintext),
            'scope' => $scope,
            'generation' => (int) BuddyTask::query()->whereKey($task->id)->value('generation'),
            'expires_at' => $expiresAt,
        ]);

        return ['ticket' => $plaintext, 'expires_at' => $expiresAt, 'model' => $ticket];
    }

    /**
     * Atomic consume: the UPDATE ... WHERE consumed_at IS NULL is the only
     * arbiter, so two concurrent exchanges yield exactly one session.
     *
     * @return array{session: BuddyViewSession, token: string}|null
     */
    public function exchange(string $plaintext, ?string $origin = null): ?array
    {
        if (! $this->originAllowed($origin)) {
            return null;
        }

        return DB::transaction(function () use ($plaintext, $origin) {
            $consumed = BuddyViewTicket::query()
                ->where('ticket_hash', $this->hash($plaintext))
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update(['consumed_at' => now()]);

            if ($consumed !== 1) {
                return null;
            }

            $ticket = BuddyViewTicket::query()->where('ticket_hash', $this->hash($plaintext))->firstOrFail();
            $task = $ticket->task;

            if ($task === null || $task->api_client_id !== $ticket->api_client_id || ! $ticket->client?->active) {
                return null;
            }

            $token = 'bvs_'.bin2hex(random_bytes(32));
            $ttl = (int) config('buddy.edge.session_ttl', 900);

            $session = BuddyViewSession::create([
                'buddy_view_ticket_id' => $ticket->id,
                'buddy_task_id' => $ticket->buddy_task_id,
                'api_client_id' => $ticket->api_client_id,
                'session_hash' => $this->hash($token),
                'scope' => $ticket->scope,
                'origin' => $origin,
                'expires_at' => now()->addSeconds($ttl),
                'hard_expires_at' => now()->addSeconds((int) config('buddy.edge.session_max_lifetime', 14400)),
                'last_seen_at' => now(),
            ]);

            return ['session' => $session, 'token' => $token];
        });
    }

    /*
     * Fresh authorization on every use: the session must be unexpired and
     * unrevoked, and the task must still belong to the session's client,
     * which must still be active. Sliding renewal never exceeds the hard cap.
     */
    public function resolve(?string $token, BuddyTask $task): ?BuddyViewSession
    {
        if ($token === null || $token === '') {
            return null;
        }

        $session = BuddyViewSession::query()->where('session_hash', $this->hash($token))->first();

        if ($session === null || ! $session->isUsable()) {
            return null;
        }

        if ($session->buddy_task_id !== $task->id || $task->api_client_id !== $session->api_client_id) {
            return null;
        }

        if (! $session->client?->active) {
            return null;
        }

        $renewed = now()->addSeconds((int) config('buddy.edge.session_ttl', 900));
        $session->forceFill([
            'last_seen_at' => now(),
            'expires_at' => $renewed->min($session->hard_expires_at),
        ])->save();

        return $session;
    }

    public function revokeForTask(BuddyTask $task): int
    {
        return BuddyViewSession::query()
            ->where('buddy_task_id', $task->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function originAllowed(?string $origin): bool
    {
        $allowed = (array) config('buddy.edge.allowed_origins', []);

        if ($allowed === []) {
            return true;
        }

        return $origin !== null && in_array($origin, $allowed, true);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
