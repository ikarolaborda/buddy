<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
 * Queue-depth signal for the worker autoscaler (ADR 0012, ADR 0014). The
 * scaler cannot dial Redis inside the environment, but it can reach this
 * endpoint over the public ingress. It reports every lane the worker
 * consumes; the 'scaling' block is what the KEDA rules read, divided by the
 * per-replica capacity of the lane. Demand is pending plus reserved: work
 * that is waiting or running, which is what a replica's capacity must cover.
 * Delayed jobs are retries with a backoff and are not runnable yet. A Redis
 * failure surfaces as a 500 so the scaler keeps its last value instead of
 * reading zero. Absent key means the feature is off and the route behaves
 * as if it did not exist.
 */
class ScalingMetricsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configured = (string) config('buddy.scaling.metrics_key');

        if ($configured === '') {
            abort(404);
        }

        if (! hash_equals($configured, (string) $request->header('X-Buddy-Scaling-Key', ''))) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $lanes = [];

        foreach ((array) config('buddy.queues.lanes') as $lane => $queue) {
            $lanes[$lane] = ['queue' => (string) $queue] + $this->measure((string) $queue);
        }

        $legacy = $lanes['legacy'] ?? ['queue' => 'default', 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'demand' => 0, 'source' => 'none'];

        // Whatever an older release left in the legacy list is evaluator work
        // served by a single compatibility process, so it counts against the
        // evaluations rule rather than needing a rule of its own.
        $scaling = [
            'evaluations' => ($lanes['evaluations']['demand'] ?? 0) + $legacy['demand'],
            'council' => $lanes['council']['demand'] ?? 0,
            'fast' => $lanes['fast']['demand'] ?? 0,
        ];

        return response()
            ->json([
                'queue' => $legacy['queue'],
                'pending' => $legacy['pending'],
                'delayed' => $legacy['delayed'],
                'reserved' => $legacy['reserved'],
                'source' => $legacy['source'],
                'lanes' => $lanes,
                'scaling' => $scaling,
                'capacity' => (array) config('buddy.queues.capacity'),
                'measured_at' => now()->toISOString(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{pending: int, delayed: int, reserved: int, demand: int, source: string}
     */
    protected function measure(string $queue): array
    {
        if (config('queue.default') !== 'redis') {
            $pending = (int) Queue::size($queue);

            return [
                'pending' => $pending,
                'delayed' => 0,
                'reserved' => 0,
                'demand' => $pending,
                'source' => (string) config('queue.default'),
            ];
        }

        $connection = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $pending = (int) $connection->llen('queues:'.$queue);
        $reserved = (int) $connection->zcard('queues:'.$queue.':reserved');

        return [
            'pending' => $pending,
            'delayed' => (int) $connection->zcard('queues:'.$queue.':delayed'),
            'reserved' => $reserved,
            'demand' => $pending + $reserved,
            'source' => 'redis',
        ];
    }
}
