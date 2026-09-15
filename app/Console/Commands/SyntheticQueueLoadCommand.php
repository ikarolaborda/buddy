<?php

namespace App\Console\Commands;

use App\Jobs\SyntheticSleepJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * Dispatches a bounded backlog of no-inference jobs so the worker autoscaler
 * can be observed scaling out and back in (P0 acceptance). Refuses to run
 * without --confirm and never exceeds 200 jobs or five minutes per job.
 */
class SyntheticQueueLoadCommand extends Command
{
    protected $signature = 'buddy:queue:synthetic
        {--count=30 : Number of jobs to dispatch (max 200)}
        {--seconds=90 : Seconds each job sleeps (max 300)}
        {--lane=evaluations : Lane to load: evaluations, council, fast or legacy}
        {--confirm : Required; acknowledges that this occupies production workers}';

    protected $description = 'Dispatch synthetic no-inference jobs for a bounded autoscaling experiment';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to dispatch synthetic load without --confirm.');

            return self::FAILURE;
        }

        $count = max(1, min(200, (int) $this->option('count')));
        $seconds = max(1, min(300, (int) $this->option('seconds')));
        $batch = 'syn-'.Str::lower(Str::random(8));
        $lane = (string) $this->option('lane');
        $queue = config('buddy.queues.lanes.'.$lane);

        if (! is_string($queue) || $queue === '') {
            $this->error("Unknown lane '{$lane}'; use evaluations, council, fast or legacy.");

            return self::FAILURE;
        }

        $this->line("Lane {$lane}, queue key under measurement: ".$this->queueKey($queue));
        $this->line('Pending before dispatch: '.$this->pending($queue));

        foreach (range(1, $count) as $index) {
            SyntheticSleepJob::dispatch($batch, $index, $seconds)->onQueue($queue);
        }

        $this->info("Dispatched {$count} synthetic job(s) of {$seconds}s each (batch {$batch}).");
        $this->line('Pending after dispatch: '.$this->pending($queue));
        $this->line('Observe: az containerapp replica list, KEDA system logs, and the queue-depth endpoint.');

        return self::SUCCESS;
    }

    protected function queueKey(string $queue): string
    {
        return (string) config('database.redis.options.prefix').'queues:'.$queue;
    }

    protected function pending(string $queue): int
    {
        if (config('queue.default') !== 'redis') {
            return (int) Queue::size($queue);
        }

        return (int) Redis::connection((string) config('queue.connections.redis.connection', 'default'))->llen('queues:'.$queue);
    }
}
