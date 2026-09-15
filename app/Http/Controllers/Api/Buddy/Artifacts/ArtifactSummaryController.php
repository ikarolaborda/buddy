<?php

namespace App\Http\Controllers\Api\Buddy\Artifacts;

use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Models\BuddyArtifact;
use App\Models\BuddyTask;
use App\Services\Artifacts\ArtifactStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtifactSummaryController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(
        protected ArtifactStorageService $storage,
    ) {}

    public function __invoke(Request $request, BuddyTask $task, BuddyArtifact $artifact): JsonResponse
    {
        abort_unless(config('buddy.edge.artifacts'), 404);
        $this->authorizeTaskAccess($request, $task);
        abort_unless($artifact->buddy_task_id === $task->id, 404);

        return response()
            ->json($this->storage->summary($task, $artifact, $request->boolean('metadata_only')))
            ->header('Cache-Control', 'private, no-store');
    }
}
