<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\BuddyTask;
use App\Services\Edge\EdgeDelegationService;
use App\Services\Edge\TaskProgressProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The supervisor's periodic authoritative read (plan §8). It answers only to
 * a delegation that still verifies against current ownership, generation and
 * scope, and it exposes lifecycle facts alone: no prompts, artifacts,
 * provider identifiers or secrets ever leave through this route.
 */
class DelegatedTaskStatusController extends Controller
{
    public function __construct(
        protected EdgeDelegationService $delegations,
    ) {}

    public function __invoke(Request $request, BuddyTask $task): JsonResponse
    {
        if (! config('buddy.edge.supervision')) {
            abort(404);
        }

        $delegation = $this->delegations->verify((string) $request->query('delegation', ''), $task, ApiScope::InterventionsExecute);

        if ($delegation === null) {
            abort(404);
        }

        return response()
            ->json([
                'task_id' => $task->ulid,
                'operation' => $task->operation,
                'is_recovery' => $task->recovery_of_task_id !== null,
                'progress' => TaskProgressProjection::for($task),
            ])
            ->header('Cache-Control', 'no-store');
    }
}
