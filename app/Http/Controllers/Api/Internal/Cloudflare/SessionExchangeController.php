<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Http\Controllers\Controller;
use App\Services\Edge\ViewTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionExchangeController extends Controller
{
    public function __construct(
        protected ViewTicketService $tickets,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! config('buddy.edge.progress')) {
            abort(404);
        }

        $validated = $request->validate([
            'ticket' => ['required', 'string', 'max:128'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        $exchanged = $this->tickets->exchange($validated['ticket'], $validated['origin'] ?? null);

        if ($exchanged === null) {
            return response()->json(['error' => 'ticket_invalid', 'message' => 'The ticket is expired, consumed, or not allowed for this origin.'], 410);
        }

        $session = $exchanged['session'];

        return response()->json([
            'session_token' => $exchanged['token'],
            'task_id' => $session->task?->ulid,
            'client_id' => (string) $session->api_client_id,
            'scope' => $session->scope,
            'expires_at' => $session->expires_at->toISOString(),
            'hard_expires_at' => $session->hard_expires_at->toISOString(),
        ], 201);
    }
}
