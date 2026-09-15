<?php

namespace App\Http\Controllers\Api\Internal\Cloudflare;

use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\BuddyTask;
use App\Services\Edge\EdgeDelegationService;
use App\Services\Interventions\InterventionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The supervisor Workflow calls this with a delegation Azure minted when the
 * task's events were published. The delegation is verified against current
 * ownership and scope; the intervention service then applies exactly the
 * same limits as the client-facing route (one recovery child, transient
 * failures only, no chains). Automatic recovery has its own flag on top.
 */
class DelegatedInterventionController extends Controller
{
    public function __construct(
        protected EdgeDelegationService $delegations,
        protected InterventionService $interventions,
    ) {}

    public function __invoke(Request $request, BuddyTask $task): JsonResponse
    {
        if (! config('buddy.edge.supervision')) {
            abort(404);
        }

        $delegation = $this->delegations->verify((string) $request->input('delegation', ''), $task, ApiScope::InterventionsExecute);

        if ($delegation === null) {
            abort(404);
        }

        $input = $request->except(['delegation']);

        if (($input['action'] ?? null) === 'recover_evaluation' && ! config('buddy.edge.auto_recovery')) {
            return response()->json(['error' => 'auto_recovery_disabled'], 422);
        }

        $client = $task->client;

        abort_unless($client !== null, 404);

        return response()->json($this->interventions->performDelegated($task, $client, $input, $delegation['id']));
    }
}
