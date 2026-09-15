<?php

namespace App\Services\Outbox\Destinations;

use App\Contracts\OutboxDestination;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/*
 * Publishes task event envelopes to the Cloudflare Queue over the Queues
 * REST API. Runs only from the relay or a queued delivery job, never from a
 * request transaction, so a Cloudflare outage cannot hold a submission open.
 * The token is a separate limited credential (Queues write only).
 */
final class CloudflareQueuePublisher implements OutboxDestination
{
    public function deliver(OutboxMessage $message): bool
    {
        if (! config('buddy.edge.events')) {
            return false;
        }

        $account = (string) config('buddy.edge.cloudflare.account_id');
        $queue = (string) config('buddy.edge.cloudflare.events_queue_id');
        $token = (string) config('buddy.edge.cloudflare.queues_token');

        if ($account === '' || $queue === '' || $token === '') {
            throw new RuntimeException('Cloudflare events destination is not configured.');
        }

        $envelope = $message->payload['envelope'] ?? null;

        if (! is_array($envelope)) {
            throw new RuntimeException('Outbox message has no event envelope.');
        }

        $response = Http::withToken($token)
            ->timeout((int) config('buddy.edge.cloudflare.publish_timeout', 5))
            ->connectTimeout(3)
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
}
