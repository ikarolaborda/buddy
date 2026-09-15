<?php

namespace App\Console\Commands;

use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Services\Outbox\OutboxTopicRegistry;
use App\Services\OutboxPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/*
 * Redacted, audited replay for remote outbox destinations (plan §6). Local
 * queue deliveries are never replayed here: they can start inference, and
 * the relay already retries them. Every run writes an audit line.
 */
class OutboxReplayCommand extends Command
{
    protected $signature = 'buddy:outbox-replay
        {--destination=cloudflare_events : Remote destination to replay}
        {--topic= : Only messages with this topic}
        {--since= : Only messages created at or after this timestamp}
        {--limit=50 : Maximum deliveries to consider}
        {--include-delivered : Also replay deliveries already marked delivered}
        {--list-quarantined : List quarantined messages and exit}
        {--dry-run : Show what would be replayed without sending}';

    protected $description = 'Replay undelivered remote outbox deliveries with an audit record';

    public function handle(OutboxPublisher $publisher, OutboxTopicRegistry $registry): int
    {
        if ($this->option('list-quarantined')) {
            return $this->listQuarantined();
        }

        $destination = (string) $this->option('destination');

        if ($destination === OutboxTopicRegistry::DESTINATION_LOCAL) {
            $this->error('Local queue deliveries cannot be replayed; use buddy:outbox-relay.');

            return self::FAILURE;
        }

        $query = OutboxDelivery::query()
            ->with('message')
            ->where('destination', $destination)
            ->whereHas('message', function ($messages) {
                $messages->whereNull('quarantined_at');

                if ($this->option('topic')) {
                    $messages->where('topic', $this->option('topic'));
                }

                if ($this->option('since')) {
                    $messages->where('created_at', '>=', $this->option('since'));
                }
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'));

        if (! $this->option('include-delivered')) {
            $query->whereNull('delivered_at');
        }

        $deliveries = $query->get();

        $rows = $deliveries->map(fn (OutboxDelivery $delivery) => [
            $delivery->outbox_message_id,
            $delivery->message->topic,
            $delivery->message->message_key,
            $delivery->attempts,
            $delivery->delivered_at?->toISOString() ?? '-',
            mb_substr((string) $delivery->last_error, 0, 60),
        ])->all();

        $this->table(['outbox_id', 'topic', 'message_key', 'attempts', 'delivered_at', 'last_error'], $rows);

        if ($this->option('dry-run')) {
            $this->info('Dry run: '.count($rows).' delivery(ies) would be replayed.');

            return self::SUCCESS;
        }

        $replayed = 0;
        $failed = 0;

        foreach ($deliveries as $delivery) {
            $result = $publisher->replayRemote($delivery->message, $destination);

            if ($result['replayed']) {
                $replayed++;

                continue;
            }

            $failed++;
            $this->warn('Replay failed for outbox '.$delivery->outbox_message_id.': '.$result['error']);
        }

        Log::info('Outbox replay', [
            'destination' => $destination,
            'topic' => $this->option('topic'),
            'since' => $this->option('since'),
            'considered' => $deliveries->count(),
            'replayed' => $replayed,
            'failed' => $failed,
            'include_delivered' => (bool) $this->option('include-delivered'),
        ]);

        $this->info("Replayed {$replayed} delivery(ies); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function listQuarantined(): int
    {
        $rows = OutboxMessage::query()
            ->whereNotNull('quarantined_at')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->map(fn ($message) => [$message->id, $message->topic, $message->message_key, $message->quarantined_at?->toISOString(), mb_substr((string) $message->last_error, 0, 80)])
            ->all();

        $this->table(['outbox_id', 'topic', 'message_key', 'quarantined_at', 'reason'], $rows);

        return self::SUCCESS;
    }
}
