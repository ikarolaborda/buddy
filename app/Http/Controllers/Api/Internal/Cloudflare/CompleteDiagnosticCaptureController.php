<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\Cloudflare\CompleteDiagnosticCaptureRequest;
use App\Models\BuddyArtifact;
use App\Models\BuddyDiagnosticCapture;
use App\Models\BuddyTask;
use App\Services\Diagnostics\DiagnosticCaptureService;
use Illuminate\Http\JsonResponse;

/*
 * Completion callback from the Worker (plan §11). The request class already
 * proved the callback token; this controller only decides whether the
 * capture is still open, and turns the report into bounded evidence.
 */
class CompleteDiagnosticCaptureController extends Controller
{
    public function __construct(
        protected DiagnosticCaptureService $captures,
    ) {}

    public function __invoke(CompleteDiagnosticCaptureRequest $request, BuddyTask $task, BuddyDiagnosticCapture $capture): JsonResponse
    {
        $result = $request->result();
        $reportedKey = $result['screenshot_object_key'] ?? null;

        if (is_string($reportedKey) && $reportedKey !== '' && $reportedKey !== $capture->screenshotObjectKey()) {
            return response()->json(['error' => 'screenshot_key_mismatch', 'capture_id' => $capture->id], 422);
        }

        if ($capture->isSettled()) {
            return $this->captures->matchesCompletion($capture, $request->status(), $result)
                ? response()->json($this->completed($capture, $capture->artifact()))
                : response()->json(['error' => 'completion_conflict', 'capture_id' => $capture->id], 409);
        }

        abort_unless($capture->isOpen(), 404);

        $settled = $this->captures->complete($capture, $request->status(), $result);

        return response()->json($this->completed($settled['capture'], $settled['artifact']));
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(BuddyDiagnosticCapture $capture, ?BuddyArtifact $artifact): array
    {
        return [
            'capture_id' => $capture->id,
            'status' => $capture->status,
            'error_code' => $capture->error_code,
            'artifact_id' => $artifact?->id,
        ];
    }
}
