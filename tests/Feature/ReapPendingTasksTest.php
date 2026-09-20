<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReapPendingTasksTest extends TestCase
{
    use RefreshDatabase;

    private function task(TaskStatus $status, int $ageHours): BuddyTask
    {
        $task = BuddyTask::create([
            'source_agent' => 'test',
            'task_summary' => 'reaper fixture',
            'problem_type' => 'other',
            'status' => $status,
        ]);

        // created_at is set by the model, so age has to be written after.
        $task->forceFill(['created_at' => now()->subHours($ageHours)])->save();

        return $task->refresh();
    }

    public function test_it_closes_tasks_left_pending_past_the_age(): void
    {
        config(['buddy.timeouts.pending_task_hours' => 24]);
        $abandoned = $this->task(TaskStatus::Pending, 30);

        $this->artisan('buddy:tasks:reap-pending')->assertSuccessful();

        $this->assertSame(TaskStatus::Closed, $abandoned->refresh()->status);
    }

    public function test_it_leaves_a_pending_task_inside_the_age_alone(): void
    {
        // The whole point of deferring is a gap between submit and evaluate.
        // Reaping inside that gap would break the feature it exists to support.
        config(['buddy.timeouts.pending_task_hours' => 24]);
        $recent = $this->task(TaskStatus::Pending, 2);

        $this->artisan('buddy:tasks:reap-pending')->assertSuccessful();

        $this->assertSame(TaskStatus::Pending, $recent->refresh()->status);
    }

    public function test_it_never_touches_a_task_that_is_evaluating(): void
    {
        config(['buddy.timeouts.pending_task_hours' => 24]);
        $running = $this->task(TaskStatus::Evaluating, 100);

        $this->artisan('buddy:tasks:reap-pending')->assertSuccessful();

        $this->assertSame(TaskStatus::Evaluating, $running->refresh()->status);
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        config(['buddy.timeouts.pending_task_hours' => 24]);
        $abandoned = $this->task(TaskStatus::Pending, 30);

        $this->artisan('buddy:tasks:reap-pending', ['--dry-run' => true])
            ->expectsOutputToContain('1 task(s) have been Pending')
            ->assertSuccessful();

        $this->assertSame(TaskStatus::Pending, $abandoned->refresh()->status);
    }

    public function test_it_survives_a_task_that_starts_evaluating_mid_sweep(): void
    {
        /*
         * transition() compares and swaps on the status it read, so a caller
         * that returns between the query and the update makes the update match
         * no rows and throw. The reaper must treat that as the caller winning,
         * not as a failure: the sweep continues and the task keeps running.
         */
        config(['buddy.timeouts.pending_task_hours' => 24]);
        $racing = $this->task(TaskStatus::Pending, 30);
        $alsoStale = $this->task(TaskStatus::Pending, 30);

        BuddyTask::query()->whereKey($racing->id)->update(['status' => TaskStatus::Evaluating->value]);

        $this->artisan('buddy:tasks:reap-pending')->assertSuccessful();

        $this->assertSame(TaskStatus::Evaluating, $racing->refresh()->status);
        $this->assertSame(TaskStatus::Closed, $alsoStale->refresh()->status, 'One raced task must not abort the sweep.');
    }
}
