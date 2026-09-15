<?php

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Services\OutboxPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;

/*
 * Fast path for remote outbox destinations. The request transaction only
 * records the intent; this job performs the network call off the request
 * path, and the relay remains the recovery path if the job is lost.
 */
#[Tries(1)]
#[Timeout(30)]
class DeliverOutboxRemoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $outboxMessageId,
    ) {
        $this->onQueue((string) config('buddy.queues.lanes.fast'));
        $this->afterCommit();
    }

    public function handle(OutboxPublisher $publisher): void
    {
        $message = OutboxMessage::query()->find($this->outboxMessageId);

        if ($message === null) {
            return;
        }

        $publisher->publish($message, local: false, remote: true);
    }
}
