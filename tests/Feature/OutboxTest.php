<?php

namespace Tests\Feature;

use App\Jobs\EvaluateTaskJob;
use App\Models\BuddyTask;
use App\Models\OutboxMessage;
use App\Services\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_appends_and_publishes_after_commit(): void
    {
        Bus::fake();

        $task = BuddyTask::factory()->create();

        $message = app(OutboxPublisher::class)->appendTaskSubmitted($task);

        $this->assertNotNull($message);
        $this->assertNotNull($message->fresh()->processed_at);

        Bus::assertDispatched(EvaluateTaskJob::class);
    }

    public function test_it_deduplicates_by_message_key(): void
    {
        Bus::fake();

        $task = BuddyTask::factory()->create();
        $publisher = app(OutboxPublisher::class);

        $first = $publisher->appendTaskSubmitted($task);
        $second = $publisher->appendTaskSubmitted($task);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, OutboxMessage::count());
    }

    public function test_relay_publishes_unprocessed_messages(): void
    {
        Bus::fake();

        $task = BuddyTask::factory()->create();

        OutboxMessage::create([
            'topic' => 'buddy.task.submitted',
            'message_key' => $task->ulid.':evaluate',
            'payload' => ['task_ulid' => $task->ulid, 'operation' => 'evaluate'],
            'available_at' => now()->subMinute(),
        ]);

        $this->artisan('buddy:outbox-relay', ['--once' => true])
            ->assertSuccessful();

        Bus::assertDispatched(EvaluateTaskJob::class);
        $this->assertNotNull(OutboxMessage::first()->processed_at);
    }

    public function test_publish_skips_already_processed_messages(): void
    {
        Bus::fake();

        $task = BuddyTask::factory()->create();

        $message = OutboxMessage::create([
            'topic' => 'buddy.task.submitted',
            'message_key' => $task->ulid.':evaluate',
            'payload' => ['task_ulid' => $task->ulid, 'operation' => 'evaluate'],
            'available_at' => now(),
            'processed_at' => now(),
        ]);

        $this->assertFalse(app(OutboxPublisher::class)->publish($message));

        Bus::assertNotDispatched(EvaluateTaskJob::class);
    }
}
