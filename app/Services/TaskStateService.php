<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use App\Services\Edge\TaskProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TaskStateService
{
    public function __construct(
        protected TaskProgressService $progress,
    ) {}

    /*
     * The atomic UPDATE ... WHERE guard is the correctness boundary for
     * concurrent workers: queue-level unique jobs and overlap locks are
     * defense in depth, but only one worker can win this row update.
     */
    public function claim(BuddyTask $task, string $owner, ?int $leaseSeconds = null): bool
    {
        $leaseSeconds ??= (int) config('buddy.timeouts.lease', 300);

        $claimed = BuddyTask::query()
            ->whereKey($task->id)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Evaluating->value])
            ->where(function ($query) {
                $query->whereNull('claimed_by')
                    ->orWhere('lease_expires_at', '<', now());
            })
            ->update([
                'status' => TaskStatus::Evaluating->value,
                'claimed_by' => $owner,
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
                'heartbeat_at' => now(),
                'worker_started_at' => now(),
                'state_version' => DB::raw('state_version + 1'),
            ]);

        if ($claimed === 1) {
            $task->refresh();

            return true;
        }

        return false;
    }

    /*
     * Reaps tasks stuck in Evaluating whose lease expired long ago: a
     * worker died mid-run (SIGKILL, crash) and nothing else ever scans
     * lease_expires_at. Grace of one lease period beyond expiry avoids
     * racing a live worker whose heartbeat is merely late.
     */
    public function reapExpiredLeases(): int
    {
        $reaped = 0;

        $stuck = BuddyTask::query()
            ->where('status', TaskStatus::Evaluating->value)
            ->whereNotNull('claimed_by')
            ->where('lease_expires_at', '<', now()->subSeconds((int) config('buddy.timeouts.lease', 300)))
            ->limit(20)
            ->get();

        foreach ($stuck as $task) {
            try {
                $this->transition($task, TaskStatus::Failed);
                $reaped++;
            } catch (\Throwable $e) {
                Log::info('Lease reap skipped (state moved)', ['task_ulid' => $task->ulid]);
            }
        }

        return $reaped;
    }

    public function heartbeat(BuddyTask $task, string $owner, ?int $leaseSeconds = null): bool
    {
        $leaseSeconds ??= (int) config('buddy.timeouts.lease', 300);

        return BuddyTask::query()
            ->whereKey($task->id)
            ->where('claimed_by', $owner)
            ->update([
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
            ]) === 1;
    }

    public function release(BuddyTask $task, string $owner): void
    {
        BuddyTask::query()
            ->whereKey($task->id)
            ->where('claimed_by', $owner)
            ->update([
                'claimed_by' => null,
                'lease_expires_at' => null,
            ]);
    }

    public function transition(BuddyTask $task, TaskStatus $next): void
    {
        if (! $task->status->canTransitionTo($next)) {
            throw new RuntimeException(
                "Invalid transition {$task->status->value} -> {$next->value} for task {$task->ulid}",
            );
        }

        $updated = BuddyTask::query()
            ->whereKey($task->id)
            ->where('status', $task->status->value)
            ->update([
                'status' => $next->value,
                'state_version' => DB::raw('state_version + 1'),
                'claimed_by' => $next->isTerminal() ? null : $task->claimed_by,
                'lease_expires_at' => $next->isTerminal() ? null : $task->lease_expires_at,
            ]);

        if ($updated !== 1) {
            throw new RuntimeException(
                "Task {$task->ulid} state changed concurrently; expected {$task->status->value}",
            );
        }

        $previous = $task->status;
        $task->refresh();
        $this->recordProgress($task, $previous, $next);
    }

    /*
     * Lifecycle facts for the dashboard and supervisor (plan §7). Inert while
     * the edge flags are off: the progress service returns without touching
     * the database, so legacy transitions keep their exact write set.
     */
    protected function recordProgress(BuddyTask $task, TaskStatus $previous, TaskStatus $next): void
    {
        if (! $this->progress->enabled()) {
            return;
        }

        if ($previous === TaskStatus::Pending && $next === TaskStatus::Evaluating) {
            BuddyTask::query()->whereKey($task->id)->update(['queued_at' => now()]);
            $task->queued_at = now();
            $this->progress->phase($task, 'queued', ['operation' => $task->operation]);

            return;
        }

        if ($next->isTerminal()) {
            $data = [];

            if ($next === TaskStatus::Failed) {
                $category = $task->runs()->orderByDesc('run_number')->value('error_category');
                BuddyTask::query()->whereKey($task->id)->update(['failure_category' => $category]);
                $task->failure_category = $category;
                $data['failure_category'] = $category;
            }

            $this->progress->terminal($task, $data);
        }
    }
}
