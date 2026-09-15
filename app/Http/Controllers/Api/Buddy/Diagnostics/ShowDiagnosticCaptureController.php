<?php

namespace App\Http\Controllers\Api\Buddy\Diagnostics;

use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Models\BuddyDiagnosticCapture;
use App\Models\BuddyTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;

#[Middleware('throttle:buddy-api')]
class ShowDiagnosticCaptureController extends Controller
{
    use AuthorizesTaskAccess;

    public function __invoke(Request $request, BuddyTask $task, BuddyDiagnosticCapture $capture): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        abort_unless($capture->buddy_task_id === $task->id, 404);

        return response()->json($capture->response());
    }
}
