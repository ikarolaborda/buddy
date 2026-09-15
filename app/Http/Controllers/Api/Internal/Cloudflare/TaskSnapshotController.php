<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Http\Controllers\Controller;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Services\Edge\TaskProgressProjection;
use App\Services\Edge\ViewTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * Authoritative snapshot for the Worker's Durable Object. It is served only
 * to a live view session bound to this task, and it carries the committed
 * progress sequence so a projection that detected a gap resumes strictly
 * above the watermark it was handed.
 */
class TaskSnapshotController extends Controller
{
    public const MAX_EVENTS = 100;

    public function __construct(
        protected ViewTicketService $tickets,
    ) {}

    public function __invoke(Request $request, BuddyTask $task): JsonResponse
    {
        if (! config('buddy.edge.progress')) {
            abort(404);
        }

        $session = $this->tickets->resolve($request->header('X-Buddy-Edge-Session'), $task);

        if ($session === null) {
            return response()->json(['error' => 'session_expired'], 401);
        }

        $after = max(0, (int) $request->query('after_sequence', 0));

        $events = $task->events()
            ->where('sequence', '>', $after)
            ->orderBy('sequence')
            ->limit(self::MAX_EVENTS)
            ->get()
            ->map(fn (BuddyTaskEvent $event) => $event->envelope($task))
            ->values();

        return response()
            ->json([
                'task_id' => $task->ulid,
                'progress' => TaskProgressProjection::for($task),
                'events' => $events,
                'events_truncated' => $events->count() === self::MAX_EVENTS,
                'session' => ['expires_at' => $session->expires_at->toISOString()],
            ])
            ->header('Cache-Control', 'no-store');
    }
}
