<?php

namespace App\Http\Controllers\Api\Buddy\Artifacts;

use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Models\BuddyArtifactUpload;
use App\Models\BuddyTask;
use App\Services\Artifacts\ArtifactStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompleteArtifactUploadController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(
        protected ArtifactStorageService $storage,
    ) {}

    public function __invoke(Request $request, BuddyTask $task, BuddyArtifactUpload $upload): JsonResponse
    {
        abort_unless(config('buddy.edge.artifacts'), 404);
        $this->authorizeTaskAccess($request, $task);
        abort_unless($upload->buddy_task_id === $task->id, 404);

        $artifact = $this->storage->finalize($upload);

        return response()
            ->json($this->storage->receipt($task, $artifact))
            ->header('Cache-Control', 'no-store');
    }
}
