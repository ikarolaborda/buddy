<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ApiScope;
use App\Models\BuddyTask;
use Illuminate\Http\Request;

/*
 * Cross-client isolation for task routes (plan §13: zero cross-client data
 * exposure). A non-owner gets 404, not 403, so task existence does not leak.
 * Tasks without an owning client remain accessible; admin keys bypass.
 */
trait AuthorizesTaskAccess
{
    protected function authorizeTaskAccess(Request $request, BuddyTask $task): void
    {
        if (! config('buddy.api.auth_required') || $task->api_client_id === null) {
            return;
        }

        $key = $request->attributes->get('api_key');

        if ($key !== null && $key->hasScope(ApiScope::Admin)) {
            return;
        }

        $client = $request->attributes->get('api_client');

        if ($client === null || $client->id !== $task->api_client_id) {
            abort(404);
        }
    }
}
