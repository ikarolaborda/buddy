<?php

namespace App\Jobs;

use App\Enums\ErrorClass;
use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use App\Services\TaskStateService;
use App\Support\ErrorClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Tries(3)]
#[Backoff(10, 30, 60)]
#[Timeout(600)]
#[FailOnTimeout]
class EvaluateTaskJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected BuddyTask $task,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->task->ulid;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('buddy:task:'.$this->task->ulid))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter((int) config('buddy.timeouts.retry_after', 240)),
        ];
    }

    public function handle(EvaluatorOptimizerService $evaluator, TaskStateService $state): void
    {
        $this->task->refresh();

        if ($this->task->isTerminal()) {
            Log::info('Skipping evaluation for terminal task', [
                'task_ulid' => $this->task->ulid,
                'status' => $this->task->status->value,
            ]);

            return;
        }

        $owner = gethostname().':'.getmypid().':'.Str::random(6);

        if (! $state->claim($this->task, $owner)) {
            Log::info('Task already claimed by another worker', [
                'task_ulid' => $this->task->ulid,
            ]);

            return;
        }

        try {
            $evaluator->evaluate($this->task);
        } catch (\Throwable $e) {
            $state->release($this->task, $owner);

            if (ErrorClassifier::classify($e) === ErrorClass::Permanent) {
                Log::error('Evaluation failed permanently', [
                    'task_ulid' => $this->task->ulid,
                    'error' => $e->getMessage(),
                ]);

                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    /**
     * The queue has given up on this job.
     *
     * This exists because executeRun no longer marks the task Failed on a
     * transient error: doing so made the task terminal, and handle() returns
     * early on a terminal task, so every retry was a no-op. With that removed a
     * task whose attempts are all exhausted would otherwise sit in Evaluating
     * for ever, so the terminal transition moves here, to the one point that
     * means "no further attempt is coming".
     */
    public function failed(?\Throwable $e): void
    {
        $this->task->refresh();

        if ($this->task->isTerminal()) {
            return;
        }

        Log::error('Evaluation failed after all attempts', [
            'task_ulid' => $this->task->ulid,
            'error' => $e?->getMessage(),
        ]);

        app(TaskStateService::class)->transition($this->task, TaskStatus::Failed);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'buddy_task:'.$this->task->ulid,
            'problem_type:'.$this->task->problem_type->value,
        ];
    }
}
