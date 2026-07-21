<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use App\Services\TaskStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected TaskStateService $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = app(TaskStateService::class);
    }

    public function test_it_allows_only_one_claim(): void
    {
        $task = BuddyTask::factory()->create();

        $this->assertTrue($this->state->claim($task, 'worker-a'));

        $fresh = BuddyTask::find($task->id);
        $this->assertFalse($this->state->claim($fresh, 'worker-b'));

        $this->assertSame('worker-a', $fresh->fresh()->claimed_by);
        $this->assertSame(TaskStatus::Evaluating, $fresh->fresh()->status);
    }

    public function test_it_allows_reclaim_after_lease_expiry(): void
    {
        $task = BuddyTask::factory()->create();

        $this->assertTrue($this->state->claim($task, 'worker-a', leaseSeconds: 60));

        BuddyTask::query()->whereKey($task->id)->update([
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($this->state->claim($task->fresh(), 'worker-b'));
        $this->assertSame('worker-b', $task->fresh()->claimed_by);
    }

    public function test_it_increments_state_version_on_claim(): void
    {
        $task = BuddyTask::factory()->create();

        $this->state->claim($task, 'worker-a');

        $this->assertSame(1, $task->fresh()->state_version);
    }

    public function test_it_rejects_invalid_transitions(): void
    {
        $task = BuddyTask::factory()->closed()->create();

        $this->expectException(\RuntimeException::class);

        $this->state->transition($task, TaskStatus::Evaluating);
    }

    public function test_it_rejects_claims_on_terminal_tasks(): void
    {
        $task = BuddyTask::factory()->completed()->create();

        $this->assertFalse($this->state->claim($task, 'worker-a'));
    }

    public function test_terminal_transition_clears_claim(): void
    {
        $task = BuddyTask::factory()->create();

        $this->state->claim($task, 'worker-a');
        $task->refresh();

        $this->state->transition($task, TaskStatus::Completed);

        $this->assertNull($task->fresh()->claimed_by);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }
}
