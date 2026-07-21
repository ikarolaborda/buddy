<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use App\Services\OutboxPublisher;
use Illuminate\Console\Command;

class OutboxRelayCommand extends Command
{
    protected $signature = 'buddy:outbox-relay
        {--once : Process the current backlog and exit}
        {--sleep=5 : Seconds to sleep between polls}
        {--batch=50 : Messages per batch}';

    protected $description = 'Republish unprocessed outbox messages to the queue';

    public function handle(OutboxPublisher $publisher): int
    {
        do {
            $published = $this->processBatch($publisher);

            if ($published > 0) {
                $this->info("Published {$published} outbox message(s).");
            }

            if (! $this->option('once') && $published === 0) {
                sleep((int) $this->option('sleep'));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    protected function processBatch(OutboxPublisher $publisher): int
    {
        $messages = OutboxMessage::query()
            ->whereNull('processed_at')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit((int) $this->option('batch'))
            ->get();

        $published = 0;

        foreach ($messages as $message) {
            if ($publisher->publish($message)) {
                $published++;
            }
        }

        return $published;
    }
}
