<?php

namespace App\Jobs;

use App\Enums\ErrorClass;
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
#[Timeout(180)]
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
