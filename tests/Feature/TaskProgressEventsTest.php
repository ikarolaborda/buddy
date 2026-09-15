<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Enums\TaskStatus;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Services\ApiKeyService;
use App\Services\Edge\TaskProgressService;
use App\Services\TaskStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskProgressEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_all_edge_flags_ship_off(): void
    {
        foreach (['events', 'progress', 'supervision', 'auto_recovery', 'artifacts', 'read_cache', 'browser_diagnostics'] as $flag) {
            $this->assertFalse(config('buddy.edge.'.$flag), $flag);
        }
    }

    public function test_transitions_write_nothing_extra_while_flags_are_off(): void
    {
        $task = BuddyTask::factory()->create();
        $state = app(TaskStateService::class);

        $state->transition($task, TaskStatus::Evaluating);
        $state->transition($task, TaskStatus::Completed);

        $this->assertSame(0, BuddyTaskEvent::count());
        $this->assertSame(0, $task->fresh()->progress_sequence);
        $this->assertNull($task->fresh()->phase);
        $this->assertNull($task->fresh()->queued_at);
    }

    public function test_transitions_record_ordered_events_and_phases_when_progress_is_on(): void
    {
        config(['buddy.edge.progress' => true]);
        $task = BuddyTask::factory()->create();
        $state = app(TaskStateService::class);

        $state->transition($task, TaskStatus::Evaluating);
        $this->assertTrue($state->claim($task, 'worker-a'));
        $task->runs()->create(['run_number' => 1, 'run_type' => 'evaluation', 'status' => 'failed', 'error_category' => 'transient']);
        $state->transition($task, TaskStatus::Failed);

        $events = BuddyTaskEvent::where('buddy_task_id', $task->id)->orderBy('sequence')->get();
        $this->assertSame([1, 2], $events->pluck('sequence')->all());
        $this->assertSame(['buddy.task.progress.v1', 'buddy.task.terminal.v1'], $events->pluck('type')->all());
        $this->assertSame('queued', $events[0]->data['phase']);
        $this->assertSame('failed', $events[1]->data['status']);
        $this->assertSame('transient', $events[1]->data['failure_category']);
        $this->assertSame(2, $task->fresh()->progress_sequence);
        $this->assertSame('terminal', $task->fresh()->phase);
        $this->assertNotNull($task->fresh()->queued_at);
        $this->assertNotNull($task->fresh()->worker_started_at);
        $this->assertSame('transient', $task->fresh()->failure_category);

        $envelope = $events[0]->envelope($task->fresh());
        $this->assertSame(1, $envelope['schema_version']);
        $this->assertSame($task->ulid, $envelope['task_id']);
        $this->assertArrayNotHasKey('prompt', $envelope['data']);
    }

    public function test_oversized_event_data_is_withheld_not_truncated(): void
    {
        config(['buddy.edge.progress' => true]);
        $task = BuddyTask::factory()->create();

        $event = app(TaskProgressService::class)->record($task, 'buddy.task.progress.v1', ['blob' => str_repeat('x', 20000)]);

        $this->assertTrue($event->data['withheld']);
        $this->assertSame(['blob'], $event->data['keys']);
    }

    public function test_status_responses_include_progress_only_when_the_flag_is_on(): void
    {
        config(['buddy.api.auth_required' => true]);
        $client = ApiClient::create(['name' => 'progress-client', 'project' => 'buddy']);
        $key = app(ApiKeyService::class)->issue($client, [ApiScope::TasksRead])['plaintext'];
        $task = BuddyTask::factory()->create(['api_client_id' => $client->id]);

        $this->withToken($key)->getJson('/api/buddy/tasks/'.$task->ulid)->assertOk()->assertJsonMissingPath('data.progress');

        config(['buddy.edge.progress' => true]);
        $this->withToken($key)->getJson('/api/buddy/tasks/'.$task->ulid)
            ->assertOk()
            ->assertJsonPath('data.progress.progress_sequence', 0)
            ->assertJsonPath('data.progress.next_poll_after_ms', 5000)
            ->assertJsonPath('data.progress.generation', 1);
    }
}
