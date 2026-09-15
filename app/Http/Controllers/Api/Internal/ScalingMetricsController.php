<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
 * Queue-depth signal for the worker autoscaler (P0, ADR 0012). The scaler
 * cannot dial Redis inside the environment, but it can reach this endpoint
 * over the public ingress. It reports the same list the worker consumes,
 * so the listLength threshold keeps its meaning. Absent key means the
 * feature is off, and the route behaves as if it did not exist.
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

        $queue = (string) config('buddy.scaling.queue', 'default');
        $measurement = $this->measure($queue);

        return response()
            ->json(['queue' => $queue] + $measurement + ['measured_at' => now()->toISOString()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{pending: int, delayed: int, reserved: int, source: string}
     */
    protected function measure(string $queue): array
    {
        if (config('queue.default') !== 'redis') {
            return [
                'pending' => (int) Queue::size($queue),
                'delayed' => 0,
                'reserved' => 0,
                'source' => (string) config('queue.default'),
            ];
        }

        $connection = Redis::connection((string) config('queue.connections.redis.connection', 'default'));

        return [
            'pending' => (int) $connection->llen('queues:'.$queue),
            'delayed' => (int) $connection->zcard('queues:'.$queue.':delayed'),
            'reserved' => (int) $connection->zcard('queues:'.$queue.':reserved'),
            'source' => 'redis',
        ];
    }
}
