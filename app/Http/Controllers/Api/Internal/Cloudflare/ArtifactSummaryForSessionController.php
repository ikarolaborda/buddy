<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Http\Controllers\Controller;
use App\Models\BuddyArtifact;
use App\Models\BuddyTask;
use App\Services\Artifacts\ArtifactStorageService;
use App\Services\Edge\ViewTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The Worker's read of an artifact summary for its KV cache (plan §10). A
 * live view session bound to this task is the only authority; the cache
 * keys on content_hash and processor_version, so metadata_only lets it
 * decide on a hit before it asks for the body.
 */
class ArtifactSummaryForSessionController extends Controller
{
    public function __construct(
        protected ViewTicketService $tickets,
        protected ArtifactStorageService $storage,
    ) {}

    public function __invoke(Request $request, BuddyTask $task, BuddyArtifact $artifact): JsonResponse
    {
        abort_unless(config('buddy.edge.artifacts'), 404);

        $session = $this->tickets->resolve($request->header('X-Buddy-Edge-Session'), $task);

        if ($session === null) {
            return response()->json(['error' => 'session_expired'], 401);
        }

        abort_unless($artifact->buddy_task_id === $task->id, 404);

        return response()
            ->json($this->storage->summary($task, $artifact, $request->boolean('metadata_only')))
            ->header('Cache-Control', 'private, no-store');
    }
}
