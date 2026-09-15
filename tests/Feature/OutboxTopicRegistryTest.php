<?php

namespace Tests\Feature;

use App\Jobs\CouncilDeliberateJob;
use App\Jobs\DeliverOutboxRemoteJob;
use App\Jobs\EvaluateTaskJob;
use App\Jobs\PrefetchEcosystemKnowledgeJob;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Models\OutboxDelivery;
use App\Models\OutboxMessage;
use App\Services\Edge\TaskProgressService;
use App\Services\Outbox\OutboxTopicRegistry;
use App\Services\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboxTopicRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_unknown_topics_are_quarantined_and_never_dispatch_evaluation(): void
    {
        Bus::fake();
        $task = BuddyTask::factory()->create();

        $message = OutboxMessage::create([
            'topic' => 'buddy.unknown.v9',
            'message_key' => $task->ulid,
            'payload' => ['task_ulid' => $task->ulid, 'operation' => 'evaluate'],
            'available_at' => now()->subMinute(),
        ]);

        $this->assertFalse(app(OutboxPublisher::class)->publish($message));

        Bus::assertNotDispatched(EvaluateTaskJob::class);
        Bus::assertNotDispatched(CouncilDeliberateJob::class);
        Bus::assertNotDispatched(PrefetchEcosystemKnowledgeJob::class);
        $this->assertNotNull($message->fresh()->quarantined_at);
        $this->assertNull($message->fresh()->processed_at);
        $this->assertStringContainsString('Unknown outbox topic', $message->fresh()->last_error);

        $this->artisan('buddy:outbox-relay', ['--once' => true])->assertSuccessful();
        Bus::assertNotDispatched(EvaluateTaskJob::class);
        $this->assertSame(1, $message->fresh()->attempts);
    }

    public function test_known_topics_route_to_their_handlers(): void
    {
        Bus::fake();
        $task = BuddyTask::factory()->create();
        $publisher = app(OutboxPublisher::class);

        $publisher->appendKnowledgePrefetchRequested($task);
        $task->operation = 'council';
        $task->save();
        $publisher->appendTaskSubmitted($task);

        Bus::assertDispatched(PrefetchEcosystemKnowledgeJob::class);
        Bus::assertDispatched(CouncilDeliberateJob::class);
        Bus::assertNotDispatched(EvaluateTaskJob::class);
        $this->assertSame(2, OutboxMessage::whereNotNull('processed_at')->count());
        $this->assertSame(2, OutboxDelivery::whereNotNull('delivered_at')->where('destination', OutboxTopicRegistry::DESTINATION_LOCAL)->count());
    }

    public function test_destinations_are_frozen_at_creation_and_legacy_rows_default_to_local(): void
    {
        Bus::fake();
        $task = BuddyTask::factory()->create();

        $message = app(OutboxPublisher::class)->appendTaskSubmitted($task);
        $this->assertSame([OutboxTopicRegistry::DESTINATION_LOCAL], $message->fresh()->destinations);

        $legacy = OutboxMessage::create([
            'topic' => 'buddy.task.submitted',
            'message_key' => $task->ulid.':legacy',
            'payload' => ['task_ulid' => $task->ulid, 'operation' => 'evaluate'],
            'available_at' => now()->subMinute(),
        ]);
        $this->assertNull($legacy->destinations);

        $this->assertTrue(app(OutboxPublisher::class)->publish($legacy));
        $this->assertSame(1, $legacy->deliveries()->count());
        Bus::assertDispatched(EvaluateTaskJob::class);
    }

    public function test_task_events_are_not_published_and_no_http_runs_while_the_flag_is_off(): void
    {
        Bus::fake();
        config(['buddy.edge.events' => false, 'buddy.edge.progress' => true]);
        $task = BuddyTask::factory()->create();

        $event = app(TaskProgressService::class)->phase($task, 'queued');

        $this->assertInstanceOf(BuddyTaskEvent::class, $event);
        $this->assertSame(0, OutboxMessage::where('topic', OutboxTopicRegistry::TOPIC_TASK_EVENT)->count());
        Bus::assertNotDispatched(DeliverOutboxRemoteJob::class);
    }

    public function test_task_events_publish_to_cloudflare_off_the_request_path_and_retry_on_failure(): void
    {
        Bus::fake([DeliverOutboxRemoteJob::class]);
        config([
            'buddy.edge.events' => true,
            'buddy.edge.cloudflare.account_id' => 'acc',
            'buddy.edge.cloudflare.events_queue_id' => 'q1',
            'buddy.edge.cloudflare.queues_token' => 'tok',
        ]);
        $task = BuddyTask::factory()->create(['api_client_id' => null]);

        app(TaskProgressService::class)->phase($task, 'queued');

        $message = OutboxMessage::where('topic', OutboxTopicRegistry::TOPIC_TASK_EVENT)->sole();
        $this->assertSame([OutboxTopicRegistry::DESTINATION_CLOUDFLARE_EVENTS], $message->destinations);
        $this->assertNull($message->processed_at);
        Http::assertNothingSent();
        Bus::assertDispatched(DeliverOutboxRemoteJob::class, fn (DeliverOutboxRemoteJob $job) => $job->outboxMessageId === $message->id);

        Http::fake(['https://api.cloudflare.com/client/v4/accounts/acc/queues/q1/messages' => Http::sequence()
            ->push(['success' => false], 500)
            ->push(['success' => true], 200)]);
        $this->assertFalse(app(OutboxPublisher::class)->publish($message));
        $delivery = $message->deliveries()->where('destination', OutboxTopicRegistry::DESTINATION_CLOUDFLARE_EVENTS)->sole();
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertNull($delivery->delivered_at);
        $this->assertNull($message->fresh()->processed_at);

        $delivery->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        $this->assertTrue(app(OutboxPublisher::class)->publish($message->fresh()));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok')
            && $request['body']['task_id'] === $task->ulid
            && $request['body']['task_sequence'] === 1
            && $request['body']['type'] === 'buddy.task.progress.v1');
        $this->assertNotNull($message->fresh()->processed_at);
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_task_events_publish_through_the_worker_when_no_queues_token_is_configured(): void
    {
        Bus::fake([DeliverOutboxRemoteJob::class]);
        config([
            'buddy.edge.events' => true,
            'buddy.edge.worker_url' => 'https://edge.example/',
            'buddy.edge.service_key' => 'svc',
            'buddy.edge.cloudflare.queues_token' => null,
            'buddy.edge.cloudflare.account_id' => null,
            'buddy.edge.cloudflare.events_queue_id' => null,
        ]);
        $task = BuddyTask::factory()->create(['api_client_id' => null]);

        app(TaskProgressService::class)->phase($task, 'queued');

        $message = OutboxMessage::where('topic', OutboxTopicRegistry::TOPIC_TASK_EVENT)->sole();
        Http::assertNothingSent();

        Http::fake(['https://edge.example/internal/events' => Http::sequence()
            ->push(['error' => 'queue_unavailable'], 500)
            ->push(['accepted' => true], 202)]);
        $this->assertFalse(app(OutboxPublisher::class)->publish($message));
        $delivery = $message->deliveries()->where('destination', OutboxTopicRegistry::DESTINATION_CLOUDFLARE_EVENTS)->sole();
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertNull($delivery->delivered_at);
        $this->assertStringContainsString('Worker event ingestion failed with HTTP 500', (string) $delivery->last_error);
        $this->assertNull($message->fresh()->processed_at);

        $delivery->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        $this->assertTrue(app(OutboxPublisher::class)->publish($message->fresh()));
        Http::assertSent(fn ($request) => $request->url() === 'https://edge.example/internal/events'
            && $request->hasHeader('X-Buddy-Edge-Key', 'svc')
            && ! $request->hasHeader('Authorization')
            && $request['task_id'] === $task->ulid
            && $request['task_sequence'] === 1
            && $request['type'] === 'buddy.task.progress.v1');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.cloudflare.com'));
        $this->assertNotNull($message->fresh()->processed_at);
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_a_configured_queues_token_keeps_the_direct_queues_path_even_with_a_worker_url(): void
    {
        Bus::fake([DeliverOutboxRemoteJob::class]);
        config([
            'buddy.edge.events' => true,
            'buddy.edge.worker_url' => 'https://edge.example',
            'buddy.edge.service_key' => 'svc',
            'buddy.edge.cloudflare.account_id' => 'acc',
            'buddy.edge.cloudflare.events_queue_id' => 'q1',
            'buddy.edge.cloudflare.queues_token' => 'tok',
        ]);
        $task = BuddyTask::factory()->create(['api_client_id' => null]);
        app(TaskProgressService::class)->phase($task, 'queued');
        $message = OutboxMessage::where('topic', OutboxTopicRegistry::TOPIC_TASK_EVENT)->sole();

        Http::fake(['https://api.cloudflare.com/client/v4/accounts/acc/queues/q1/messages' => Http::response(['success' => true], 200)]);
        $this->assertTrue(app(OutboxPublisher::class)->publish($message));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_replay_command_refuses_local_and_replays_remote_deliveries(): void
    {
        Bus::fake();
        config([
            'buddy.edge.events' => true,
            'buddy.edge.cloudflare.account_id' => 'acc',
            'buddy.edge.cloudflare.events_queue_id' => 'q1',
            'buddy.edge.cloudflare.queues_token' => 'tok',
        ]);
        $task = BuddyTask::factory()->create();
        app(TaskProgressService::class)->phase($task, 'queued');
        $message = OutboxMessage::where('topic', OutboxTopicRegistry::TOPIC_TASK_EVENT)->sole();

        $this->artisan('buddy:outbox-replay', ['--destination' => OutboxTopicRegistry::DESTINATION_LOCAL])->assertFailed();

        $this->artisan('buddy:outbox-replay', ['--dry-run' => true])->assertSuccessful();
        Http::assertNothingSent();

        Http::fake(['https://api.cloudflare.com/client/v4/accounts/acc/queues/q1/messages' => Http::response(['success' => true], 200)]);
        $this->artisan('buddy:outbox-replay')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertNotNull($message->deliveries()->where('destination', OutboxTopicRegistry::DESTINATION_CLOUDFLARE_EVENTS)->sole()->delivered_at);
        Bus::assertNotDispatched(EvaluateTaskJob::class);
    }
}
