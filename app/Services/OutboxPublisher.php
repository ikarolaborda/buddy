<?php

namespace App\Services;

use App\Jobs\DeliverOutboxRemoteJob;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Services\Outbox\Destinations\CloudflareQueuePublisher;
use App\Services\Outbox\OutboxTopicRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class OutboxPublisher
{
    public const CLAIM_SECONDS = 60;

    protected ?OutboxTopicRegistry $registry = null;

    public function __construct(?OutboxTopicRegistry $registry = null)
    {
        $this->registry = $registry;
    }

    /*
     * Resolved lazily so test doubles that extend the publisher without
     * calling this constructor keep working.
     */
    protected function registry(): OutboxTopicRegistry
    {
        return $this->registry ??= app(OutboxTopicRegistry::class);
    }

    public function appendKnowledgePrefetchRequested(BuddyTask $task): ?OutboxMessage
    {
        return $this->append(
            topic: OutboxTopicRegistry::TOPIC_KNOWLEDGE_PREFETCH,
            messageKey: $task->ulid,
            payload: ['task_ulid' => $task->ulid],
        );
    }

    /*
     * Append inside the caller's transaction, then publish after commit.
     * The immediate dispatch is the fast path; the relay command is the
     * recovery path that republishes anything a crashed process left
     * unprocessed. Domain truth lives in PostgreSQL either way.
     */
    public function appendTaskSubmitted(BuddyTask $task): ?OutboxMessage
    {
        return $this->append(
            topic: OutboxTopicRegistry::TOPIC_TASK_SUBMITTED,
            messageKey: $task->ulid.':'.$task->operation,
            payload: [
                'task_ulid' => $task->ulid,
                'operation' => $task->operation,
            ],
        );
    }

    /*
     * Task events carry their full envelope so the remote destination never
     * has to read PostgreSQL, and the event ULID is the message key so a
     * replayed transaction cannot publish the same event twice.
     */
    public function appendTaskEvent(BuddyTask $task, BuddyTaskEvent $event): ?OutboxMessage
    {
        return $this->append(
            topic: OutboxTopicRegistry::TOPIC_TASK_EVENT,
            messageKey: $event->id,
            payload: [
                'task_ulid' => $task->ulid,
                'event_id' => $event->id,
                'envelope' => $event->envelope($task),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function append(string $topic, string $messageKey, array $payload): ?OutboxMessage
    {
        $destinations = $this->registry()->destinationsFor($topic);

        try {
            $message = DB::transaction(function () use ($topic, $messageKey, $payload, $destinations) {
                $message = OutboxMessage::create([
                    'topic' => $topic,
                    'message_key' => $messageKey,
                    'payload' => $payload,
                    'destinations' => $destinations,
                    'available_at' => now(),
                ]);

                foreach ($destinations as $destination) {
                    $message->deliveries()->create(['destination' => $destination]);
                }

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        DB::afterCommit(function () use ($message) {
            // Local queue dispatch is cheap and idempotent (unique jobs), so
            // it runs right after commit. Remote destinations are network
            // calls and must never run inside the request path (plan §6).
            $this->publish($message, local: true, remote: false);

            if ($this->hasRemoteDestinations($message)) {
                DeliverOutboxRemoteJob::dispatch($message->id);
            }
        });

        return $message;
    }

    /**
     * Deliver every due destination of the message. Returns true only when
     * all destinations have been delivered, which is also when the message
     * is acknowledged with processed_at.
     */
    public function publish(OutboxMessage $message, bool $local = true, bool $remote = true): bool
    {
        if (! $this->registry()->knows($message->topic)) {
            $this->quarantine($message, 'Unknown outbox topic "'.$message->topic.'"; no handler or destination is registered.');

            return false;
        }

        $pending = OutboxMessage::query()
            ->whereKey($message->id)
            ->whereNull('processed_at')
            ->whereNull('quarantined_at')
            ->update([
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($pending !== 1) {
            return false;
        }

        $this->ensureDeliveries($message);

        $allDelivered = true;

        foreach ($message->deliveries()->orderBy('id')->get() as $delivery) {
            if ($delivery->delivered_at !== null) {
                continue;
            }

            $isLocal = $delivery->destination === OutboxTopicRegistry::DESTINATION_LOCAL;

            if (($isLocal && ! $local) || (! $isLocal && ! $remote)) {
                $allDelivered = false;

                continue;
            }

            if ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture()) {
                $allDelivered = false;

                continue;
            }

            if (! $this->claim($delivery)) {
                $allDelivered = false;

                continue;
            }

            try {
                $delivered = $isLocal
                    ? $this->deliverLocally($message)
                    : $this->deliverRemotely($message, $delivery->destination);

                if ($delivered) {
                    $this->settle($delivery, [
                        'delivered_at' => now(),
                        'last_error' => null,
                    ]);

                    continue;
                }

                // Deferred (feature disabled): release without counting an attempt.
                $this->settle($delivery, [
                    'next_attempt_at' => now()->addMinutes(10),
                    'last_error' => 'Deferred: destination disabled.',
                ]);
                $allDelivered = false;
            } catch (\Throwable $e) {
                $allDelivered = false;
                $attempts = $delivery->attempts + 1;

                $this->settle($delivery, [
                    'attempts' => $attempts,
                    'next_attempt_at' => $isLocal ? null : now()->addSeconds($this->backoffSeconds($attempts)),
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                ]);

                OutboxMessage::query()
                    ->whereKey($message->id)
                    ->whereNull('processed_at')
                    ->update([
                        'last_error' => mb_substr($delivery->destination.': '.$e->getMessage(), 0, 2000),
                    ]);

                Log::error('Outbox publish failed', [
                    'outbox_id' => $message->id,
                    'destination' => $delivery->destination,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $allDelivered) {
            return false;
        }

        OutboxMessage::query()
            ->whereKey($message->id)
            ->whereNull('processed_at')
            ->update(['processed_at' => now(), 'last_error' => null]);

        return true;
    }

    /**
     * Remote-only replay for operators. Local destinations are never
     * replayed once delivered because that could start new inference.
     *
     * @return array{replayed: bool, error: string|null}
     */
    public function replayRemote(OutboxMessage $message, string $destination): array
    {
        if ($destination === OutboxTopicRegistry::DESTINATION_LOCAL) {
            return ['replayed' => false, 'error' => 'Local destinations cannot be replayed.'];
        }

        if (! $this->registry()->knows($message->topic)) {
            return ['replayed' => false, 'error' => 'Unknown topic.'];
        }

        try {
            $delivered = $this->deliverRemotely($message, $destination);
        } catch (\Throwable $e) {
            return ['replayed' => false, 'error' => $e->getMessage()];
        }

        if (! $delivered) {
            return ['replayed' => false, 'error' => 'Destination disabled.'];
        }

        $message->deliveries()->updateOrCreate(
            ['destination' => $destination],
            ['delivered_at' => now(), 'last_error' => null, 'claim_token' => null, 'claimed_until' => null],
        );

        return ['replayed' => true, 'error' => null];
    }

    /*
     * Test seam and the only place a local job is dispatched. Subclasses in
     * tests override it to simulate a crash between enqueue and acknowledgement.
     */
    protected function dispatchFor(OutboxMessage $message): void
    {
        $handler = $this->registry()->handlerFor($message->topic);

        if ($handler === null) {
            return;
        }

        app($handler)->handle($message);
    }

    protected function deliverLocally(OutboxMessage $message): bool
    {
        $this->dispatchFor($message);

        return true;
    }

    protected function deliverRemotely(OutboxMessage $message, string $destination): bool
    {
        $publisher = match ($destination) {
            OutboxTopicRegistry::DESTINATION_CLOUDFLARE_EVENTS => app(CloudflareQueuePublisher::class),
            default => throw new RuntimeException('Unknown outbox destination "'.$destination.'".'),
        };

        return $publisher->deliver($message);
    }

    /*
     * Claims are written by a query, not the model, so the release must be
     * too: a model save would see no dirty attribute and leave the claim in
     * place, blocking every retry until the lease expired.
     *
     * @param  array<string, mixed>  $values
     */
    protected function settle(OutboxDelivery $delivery, array $values): void
    {
        OutboxDelivery::query()
            ->whereKey($delivery->id)
            ->update($values + ['claim_token' => null, 'claimed_until' => null, 'updated_at' => now()]);
    }

    protected function claim(OutboxDelivery $delivery): bool
    {
        $token = Str::random(32);

        $claimed = OutboxDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('delivered_at')
            ->where(function ($query) {
                $query->whereNull('claimed_until')->orWhere('claimed_until', '<', now());
            })
            ->update([
                'claim_token' => $token,
                'claimed_until' => now()->addSeconds(self::CLAIM_SECONDS),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $delivery->claim_token = $token;

        return true;
    }

    /*
     * Rows written before the deliveries table existed carry no destinations;
     * they were all local-queue messages, so the registry's current routing
     * for their topic is the documented legacy behavior.
     */
    protected function ensureDeliveries(OutboxMessage $message): void
    {
        if ($message->deliveries()->exists()) {
            return;
        }

        $destinations = $message->destinations ?? $this->registry()->destinationsFor($message->topic);

        foreach ($destinations as $destination) {
            $message->deliveries()->firstOrCreate(['destination' => $destination]);
        }
    }

    protected function hasRemoteDestinations(OutboxMessage $message): bool
    {
        foreach ($message->destinations ?? [] as $destination) {
            if ($destination !== OutboxTopicRegistry::DESTINATION_LOCAL) {
                return true;
            }
        }

        return false;
    }

    protected function quarantine(OutboxMessage $message, string $reason): void
    {
        OutboxMessage::query()
            ->whereKey($message->id)
            ->whereNull('processed_at')
            ->update([
                'quarantined_at' => now(),
                'last_error' => $reason,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        Log::error('Outbox message quarantined', [
            'outbox_id' => $message->id,
            'topic' => $message->topic,
            'reason' => $reason,
        ]);
    }

    protected function backoffSeconds(int $attempts): int
    {
        return (int) min(3600, 30 * (2 ** min($attempts, 8)));
    }
}
