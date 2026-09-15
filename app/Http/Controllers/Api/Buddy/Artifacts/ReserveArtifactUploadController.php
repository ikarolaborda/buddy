<?php

namespace App\Http\Controllers\Api\Buddy\Artifacts;

use App\Enums\ArtifactType;
use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buddy\ReserveArtifactUploadRequest;
use App\Models\BuddyTask;
use App\Services\Artifacts\ArtifactStorageService;
use Illuminate\Http\JsonResponse;

class ReserveArtifactUploadController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(
        protected ArtifactStorageService $storage,
    ) {}

    public function __invoke(ReserveArtifactUploadRequest $request, BuddyTask $task): JsonResponse
    {
        abort_unless(config('buddy.edge.artifacts'), 404);
        $this->authorizeTaskAccess($request, $task);

        if ($task->isTerminal()) {
            return response()->json(['error' => 'task_terminal'], 422);
        }

        // Bytes and keys are bound to the task's owner; an admin acting on
        // another client's task still spends that client's quota.
        $client = $task->client ?? $request->attributes->get('api_client');

        if ($client === null) {
            return response()->json(['error' => 'client_required'], 422);
        }

        $reservation = $this->storage->reserve(
            $task,
            $client,
            ArtifactType::from((string) $request->validated('type')),
            (int) $request->validated('size_bytes'),
            (string) $request->validated('media_type'),
        );
        $upload = $reservation['upload'];

        return response()
            ->json([
                'upload_id' => $upload->id,
                'task_id' => $task->ulid,
                'type' => $upload->artifact_type->value,
                'media_type' => $upload->media_type,
                'size_bytes' => $upload->declared_size,
                'upload' => [
                    'method' => 'PUT',
                    'url' => $reservation['url'],
                    'headers' => $reservation['headers'],
                ],
                'expires_at' => $upload->expires_at->toISOString(),
                'complete_url' => route('buddy.tasks.artifact_uploads.complete', ['task' => $task->ulid, 'upload' => $upload->id]),
            ], 201)
            ->header('Cache-Control', 'no-store');
    }
}
