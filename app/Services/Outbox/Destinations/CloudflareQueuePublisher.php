<?php

namespace App\Services\Outbox\Destinations;

use App\Contracts\OutboxDestination;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/*
 * Publishes task event envelopes to the Cloudflare Queue. With a Queues
 * token configured it calls the Queues REST API directly; without one it
 * hands the envelope to the buddy-edge Worker, which enqueues it with its
 * own binding, so Azure needs no Cloudflare API credential at all. Runs
 * only from the relay or a queued delivery job, never from a request
 * transaction, so a Cloudflare outage cannot hold a submission open.
 */
final class CloudflareQueuePublisher implements OutboxDestination
{
    public const WORKER_PATH = '/internal/events';

    private const CONNECT_TIMEOUT_SECONDS = 3;

    public function deliver(OutboxMessage $message): bool
    {
        if (! config('buddy.edge.events')) {
            return false;
        }

        $token = (string) config('buddy.edge.cloudflare.queues_token');
        $workerUrl = (string) config('buddy.edge.worker_url');

        if ($token === '' && $workerUrl !== '') {
            return $this->publishThroughWorker($workerUrl, $this->envelope($message));
        }

        $account = (string) config('buddy.edge.cloudflare.account_id');
        $queue = (string) config('buddy.edge.cloudflare.events_queue_id');

        if ($account === '' || $queue === '' || $token === '') {
            throw new RuntimeException('Cloudflare events destination is not configured.');
        }

        $envelope = $this->envelope($message);

        $response = Http::withToken($token)
            ->timeout($this->timeout())
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->acceptJson()
            ->post(rtrim((string) config('buddy.edge.cloudflare.api_base'), '/').'/accounts/'.$account.'/queues/'.$queue.'/messages', [
                'body' => $envelope,
                'content_type' => 'json',
            ]);

        if (! $response->successful() || $response->json('success') === false) {
            throw new RuntimeException('Cloudflare queue publish failed with HTTP '.$response->status().'.');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function publishThroughWorker(string $workerUrl, array $envelope): bool
    {
        $serviceKey = (string) config('buddy.edge.service_key');

        if ($serviceKey === '') {
            throw new RuntimeException('Cloudflare events destination is not configured.');
        }

        $response = Http::withHeaders(['X-Buddy-Edge-Key' => $serviceKey])
            ->timeout($this->timeout())
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->acceptJson()
            ->post(rtrim($workerUrl, '/').self::WORKER_PATH, $envelope);

        if (! $response->successful()) {
            throw new RuntimeException('Worker event ingestion failed with HTTP '.$response->status().'.');
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(OutboxMessage $message): array
    {
        $envelope = $message->payload['envelope'] ?? null;

        if (! is_array($envelope)) {
            throw new RuntimeException('Outbox message has no event envelope.');
        }

        return $envelope;
    }

    private function timeout(): int
    {
        return (int) config('buddy.edge.cloudflare.publish_timeout', 5);
    }
}
