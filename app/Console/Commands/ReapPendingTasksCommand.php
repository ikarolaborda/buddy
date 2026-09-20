<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use App\Services\TaskStateService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/*
 * A task is created Pending and stays there until something asks for it to be
 * evaluated. Both surfaces can leave one behind: POST /api/buddy/tasks has
 * always created Pending and relied on a second call to /evaluate, and
 * buddy.submit_problem can now do the same with defer_evaluation so that
 * evidence can be attached before the evaluator compiles its prompt.
 *
 * The caller that abandons the sequence is, by definition, not around to close
 * the task, and nothing else closes it: close_task needs someone to call it.
 * So a crashed agent leaves a row that is indistinguishable from one about to
 * be evaluated, and a client that resumes and polls it waits for ever.
 *
 * Closing rather than deleting is deliberate. The evidence attached to the task
 * is usually the expensive part and someone may still want to read it; what is
 * wrong with an abandoned task is not that it exists but that its status lies.
 */
class ReapPendingTasksCommand extends Command
{
    protected $signature = 'buddy:tasks:reap-pending
        {--dry-run : Report what would be closed without changing any row}';

    protected $description = 'Close tasks left Pending past the configured age because their caller never asked for an evaluation';

    public function handle(TaskStateService $state): int
    {
        $dry = (bool) $this->option('dry-run');
        $hours = max(1, (int) config('buddy.timeouts.pending_task_hours', 24));

        if ($dry) {
            $this->info(sprintf(
                '%d task(s) have been Pending for more than %d hour(s) (dry run).',
                $this->stale($hours)->count(),
                $hours,
            ));

            return self::SUCCESS;
        }

        $closed = 0;
        $raced = 0;

        foreach ($this->stale($hours)->lazyById(200) as $task) {
            try {
                $state->transition($task, TaskStatus::Closed);
                $closed++;
            } catch (RuntimeException $e) {
                /*
                 * transition() compares and swaps on the status it read, so a
                 * task that started evaluating between the query and the update
                 * fails here. That is the right outcome - the caller came back -
                 * and the next run will not see it at all.
                 */
                $raced++;

                Log::info('Skipped reaping a task that stopped being pending', [
                    'task_ulid' => $task->ulid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Closed %d task(s) pending for more than %d hour(s); skipped %d that started evaluating.',
            $closed,
            $hours,
            $raced,
        ));

        return self::SUCCESS;
    }

    private function stale(int $hours): Builder
    {
        return BuddyTask::query()
            ->where('status', TaskStatus::Pending->value)
            ->where('created_at', '<', now()->subHours($hours));
    }
}
