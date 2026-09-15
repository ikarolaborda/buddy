<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/*
 * Synthetic load for the P0 scale-out experiment (plan §4, step 11). It
 * occupies a worker for a bounded time and performs no model call, no
 * database write and no outbox activity, so a backlog of these jobs proves
 * only what it is meant to prove: that the autoscaler sees the queue.
 */
#[Tries(1)]
#[Timeout(600)]
class SyntheticSleepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $batchId,
        public readonly int $index,
        public readonly int $seconds,
    ) {
        $this->onQueue((string) config('buddy.queues.lanes.evaluations'));
    }

    public function handle(): void
    {
        $seconds = max(0, min(300, $this->seconds));

        Log::info('Synthetic queue load job started', ['batch' => $this->batchId, 'index' => $this->index, 'seconds' => $seconds]);

        sleep($seconds);

        Log::info('Synthetic queue load job finished', ['batch' => $this->batchId, 'index' => $this->index]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['synthetic:'.$this->batchId];
    }
}
