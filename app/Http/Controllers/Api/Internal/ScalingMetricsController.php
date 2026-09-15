<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\Queue\LaneGauge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * Queue-depth signal for the worker autoscaler (ADR 0012, ADR 0014). The
 * scaler cannot dial Redis inside the environment, but it can reach this
 * endpoint over the public ingress. The 'scaling' block is what the KEDA
 * rules read, divided by the per-replica capacity of the lane; 'waiting'
 * is the PostgreSQL view of tasks not yet claimed by any worker. A Redis
 * failure surfaces as a 500 so the scaler keeps its last value instead of
 * reading zero. Absent key means the feature is off and the route behaves
 * as if it did not exist.
 */
class ScalingMetricsController extends Controller
{
    public function __invoke(Request $request, LaneGauge $gauge): JsonResponse
    {
        $configured = (string) config('buddy.scaling.metrics_key');

        if ($configured === '') {
            abort(404);
        }

        if (! hash_equals($configured, (string) $request->header('X-Buddy-Scaling-Key', ''))) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $lanes = $gauge->lanes();
        $legacy = $lanes['legacy'] ?? ['queue' => 'default', 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'demand' => 0, 'source' => 'none'];

        return response()
            ->json([
                'queue' => $legacy['queue'],
                'pending' => $legacy['pending'],
                'delayed' => $legacy['delayed'],
                'reserved' => $legacy['reserved'],
                'source' => $legacy['source'],
                'lanes' => $lanes,
                'scaling' => $gauge->scaling($lanes),
                'capacity' => (array) config('buddy.queues.capacity'),
                'waiting' => $gauge->waiting(),
                'measured_at' => now()->toISOString(),
            ])
            ->header('Cache-Control', 'no-store');
    }
}
