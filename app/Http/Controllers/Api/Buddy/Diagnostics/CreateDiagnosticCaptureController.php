<?php

namespace App\Http\Controllers\Api\Buddy\Diagnostics;

use App\Enums\CaptureOutcome;
use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buddy\CreateDiagnosticCaptureRequest;
use App\Models\BuddyDiagnosticCapture;
use App\Models\BuddyTask;
use App\Services\Diagnostics\CaptureTargetPolicy;
use App\Services\Diagnostics\DiagnosticCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\Middleware;

/*
 * P7 authorized diagnostics (plan §11, ADR 0013). The live flag is checked
 * before any row is written so the table stays empty until gate G7 passes;
 * a denied target is recorded so the caller and the operator share one
 * audit trail of what was refused and why.
 */
#[Middleware('throttle:buddy-api')]
class CreateDiagnosticCaptureController extends Controller
{
    use AuthorizesTaskAccess;

    public function __construct(
        protected DiagnosticCaptureService $captures,
        protected CaptureTargetPolicy $policy,
    ) {}

    public function __invoke(CreateDiagnosticCaptureRequest $request, BuddyTask $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        if ($task->api_client_id === null) {
            return response()->json(['error' => 'owner_required', 'message' => 'Diagnostic captures require a task with an owning client.'], 422);
        }

        if ($task->isTerminal()) {
            return response()->json(['error' => 'task_terminal', 'message' => 'Diagnostic captures require an open task.'], 422);
        }

        if (! config('buddy.edge.browser_diagnostics')) {
            return response()->json([
                'error' => 'browser_diagnostics_disabled',
                'message' => 'Live browser capture stays disabled until the billing and network gates pass (plan G7).',
            ], 503);
        }

        $creation = $this->captures->create($task, $request->arguments($this->policy));

        return match ($creation->outcome) {
            CaptureOutcome::Queued, CaptureOutcome::Replayed, CaptureOutcome::Denied => $this->captureResponse($task, $creation->capture),
            CaptureOutcome::Conflict => response()->json([
                'error' => 'request_id_conflict',
                'message' => 'Request ID already used with a different payload.',
                'capture_id' => $creation->capture?->id,
            ], 409),
            CaptureOutcome::QuotaExhausted => response()->json([
                'error' => 'quota_exhausted',
                'message' => 'The daily capture allowance for this client is used up.',
            ], 422),
            CaptureOutcome::CapacityExhausted => response()->json([
                'error' => 'capacity_exhausted',
                'message' => 'All capture sessions are busy; retry shortly.',
            ], 422),
        };
    }

    private function captureResponse(BuddyTask $task, ?BuddyDiagnosticCapture $capture): JsonResponse
    {
        abort_if($capture === null, 500);

        if ($capture->status === BuddyDiagnosticCapture::STATUS_DENIED) {
            return response()->json(['error' => $capture->error_code, 'capture_id' => $capture->id], 422);
        }

        return response()->json([
            'capture_id' => $capture->id,
            'status' => $capture->status,
            'poll' => route('buddy.tasks.diagnostic_captures.show', ['task' => $task, 'capture' => $capture]),
        ], 202);
    }
}
