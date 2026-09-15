<?php

namespace App\Http\Controllers\Api\Buddy;

use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Services\Edge\ViewTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;

#[Middleware('throttle:buddy-api')]
class ViewTicketController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(
        protected ViewTicketService $tickets,
    ) {}

    public function __invoke(Request $request, BuddyTask $task): JsonResponse
    {
        if (! config('buddy.edge.progress')) {
            abort(404);
        }

        $this->authorizeTaskAccess($request, $task);

        $client = $request->attributes->get('api_client');

        if (! $client instanceof ApiClient || $task->api_client_id !== $client->id) {
            return response()->json(['error' => 'owner_required', 'message' => 'View tickets require the owning client.'], 422);
        }

        $minted = $this->tickets->mint($task, $client);

        return response()->json([
            'task_id' => $task->ulid,
            'ticket' => $minted['ticket'],
            'scope' => ViewTicketService::SCOPE_VIEW,
            'expires_at' => $minted['expires_at']->toISOString(),
        ], 201);
    }
}
