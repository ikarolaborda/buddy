<?php

namespace App\Http\Controllers\Api\Buddy\Artifacts;

use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Models\BuddyArtifact;
use App\Models\BuddyTask;
use App\Services\Artifacts\ArtifactStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DownloadArtifactController extends Controller
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

        $download = $this->storage->downloadUrl($artifact);

        // The signed URL is a short-lived capability: never cached, never logged.
        return response()
            ->json([
                'artifact_id' => $artifact->id,
                'task_id' => $task->ulid,
                'url' => $download['url'],
                'expires_at' => $download['expires_at']->toISOString(),
                'filename' => $download['filename'],
                'media_type' => $download['media_type'],
                'size_bytes' => $artifact->size_bytes,
                'content_hash' => $artifact->sha256,
            ])
            ->header('Cache-Control', 'no-store');
    }
}
