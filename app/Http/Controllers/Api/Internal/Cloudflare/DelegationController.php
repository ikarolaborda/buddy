<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\BuddyTask;
use App\Services\Edge\EdgeDelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/*
 * The supervisor Workflow trades a delivered event for a delegation (plan §8).
 * Possession of an event id proves the caller received this task's events
 * through the queue, so the service key alone never mints anything. Every
 * refusal is the same 404: the surface must not reveal whether a task, a
 * client or an event exists, nor which check failed.
 */
class DelegationController extends Controller
{
    public const EVENT_MAX_AGE_HOURS = 48;

    public function __construct(
        protected EdgeDelegationService $delegations,
    ) {}

    public function __invoke(Request $request, BuddyTask $task): JsonResponse
    {
        if (! config('buddy.edge.supervision')) {
            abort(404);
        }

        $eventId = $request->input('event_id');
        $clientId = $request->input('client_id');

        if (! is_string($eventId) || $eventId === '' || ! $this->isIdentifier($clientId)) {
            abort(404);
        }

        $delivered = $task->events()
            ->whereKey($eventId)
            ->where('occurred_at', '>', now()->subHours(self::EVENT_MAX_AGE_HOURS))
            ->exists();

        if (! $delivered) {
            abort(404);
        }

        if ($task->api_client_id === null || (string) $task->api_client_id !== (string) $clientId) {
            abort(404);
        }

        if (! $this->delegations->clientCurrentlyHolds($task, ApiScope::InterventionsExecute)) {
            abort(404);
        }

        $token = $this->delegations->mint($task, [ApiScope::InterventionsExecute]);

        // Read expiry and generation back from the signed token so the
        // response can never disagree with what the token itself carries,
        // and so a token that would not verify is never handed out.
        $claims = $this->delegations->verify($token, $task, ApiScope::InterventionsExecute);

        if ($claims === null) {
            abort(404);
        }

        return response()
            ->json([
                'delegation' => $token,
                'expires_at' => Carbon::createFromTimestamp($claims['exp'])->toISOString(),
                'generation' => $claims['gen'],
            ], 201)
            ->header('Cache-Control', 'no-store');
    }

    private function isIdentifier(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && $value !== '' && ctype_digit($value);
    }
}
