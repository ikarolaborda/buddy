<?php

namespace App\Services\Queue;

use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
 * One reading of the queue lanes for the autoscaler, the health job and the
 * operator (ADR 0014). Demand is pending plus reserved: work waiting or
 * running, which is what a replica's capacity must cover. Delayed jobs are
 * retries with a backoff and are not runnable yet. Waiting tasks come from
 * PostgreSQL, the authority: a task accepted (queued_at) but not yet claimed
 * (worker_started_at) is the lane-level latency signal Redis cannot give.
 */
final class LaneGauge
{
    /**
     * @return array<string, array{queue: string, pending: int, delayed: int, reserved: int, demand: int, source: string}>
     */
    public function lanes(): array
    {
        $lanes = [];

        foreach ((array) config('buddy.queues.lanes') as $lane => $queue) {
            $lanes[$lane] = ['queue' => (string) $queue] + $this->measure((string) $queue);
        }

        return $lanes;
    }

    /**
     * Whatever an older release left in the legacy list is evaluator work
     * served by a single compatibility process, so it counts against the
     * evaluations rule rather than needing a rule of its own.
     *
     * @param  array<string, array{demand: int}>  $lanes
     * @return array{evaluations: int, council: int, fast: int}
     */
    public function scaling(array $lanes): array
    {
        return [
            'evaluations' => ($lanes['evaluations']['demand'] ?? 0) + ($lanes['legacy']['demand'] ?? 0),
            'council' => $lanes['council']['demand'] ?? 0,
            'fast' => $lanes['fast']['demand'] ?? 0,
        ];
    }

    /**
     * @return array<string, array{count: int, oldest_wait_s: int}>
     */
    public function waiting(): array
    {
        $now = now();

        return BuddyTask::query()
            ->whereNotNull('queued_at')
            ->whereNull('worker_started_at')
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Closed])
            ->get(['operation', 'queued_at'])
            ->groupBy('operation')
            ->map(fn ($tasks) => [
                'count' => $tasks->count(),
                'oldest_wait_s' => (int) $tasks->max(fn (BuddyTask $task) => max(0, $now->getTimestamp() - $task->queued_at->getTimestamp())),
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * @return array{pending: int, delayed: int, reserved: int, demand: int, source: string}
     */
    public function measure(string $queue): array
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
