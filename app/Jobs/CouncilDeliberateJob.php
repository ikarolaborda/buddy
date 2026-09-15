<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use App\Services\TaskStateService;
use App\Support\ErrorClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Tries(1): a council bills real money on every attempt; a flaky run
 * must fail loudly and be re-paid deliberately, never retried silently
 * (ADR 0009 cost control). Timeout must stay under the queue
 * retry_after or a still-deliberating council gets redelivered.
 */
#[Tries(1)]
#[Timeout(1800)]
#[FailOnTimeout]
class CouncilDeliberateJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Serialized before dispatch so Laravel's fresh failed() instance has the same owner.
    public readonly string $executionOwner;

    public function __construct(
        protected BuddyTask $task,
    ) {
        $this->executionOwner = 'council:'.Str::uuid();
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'council:'.$this->task->ulid;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('buddy:council:'.$this->task->ulid))
                ->expireAfter((int) config('buddy.timeouts.council_lease', 2400))
                ->shared(),
        ];
    }

    public function handle(EvaluatorOptimizerService $evaluator, TaskStateService $state): void
    {
        $this->task->refresh();

        if ($this->task->operation !== 'council') {
            return;
        }

        if ($this->task->isTerminal()) {
            Log::info('Skipping council for terminal task', ['task_ulid' => $this->task->ulid]);

            return;
        }

        if (! config('buddy_agents.council.enabled')) {
            Log::warning('Council disabled; task left pending', ['task_ulid' => $this->task->ulid]);

            return;
        }

        $owner = $this->executionOwner;

        if (! $state->claim($this->task, $owner, (int) config('buddy.timeouts.council_lease', 2400))) {
            Log::info('Council task already claimed', ['task_ulid' => $this->task->ulid]);

            return;
        }

        $today = BuddyRun::query()
            ->where('run_type', 'council')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($today >= (int) config('buddy_agents.council.max_per_day', 10)) {
            Log::warning('Council daily cap reached', ['task_ulid' => $this->task->ulid, 'today' => $today]);
            $error = new \RuntimeException('Council daily cap reached ('.$today.').');
            $this->failed($error);
            $this->fail($error);

            return;
        }

        try {
            $evaluator->council($this->task, $owner);
        } catch (\Throwable $e) {
            $this->failed($e);

            Log::error('Council failed', ['task_ulid' => $this->task->ulid, 'error' => $e->getMessage()]);

            $this->fail($e);
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['buddy_council:'.$this->task->ulid];
    }

    public function failed(?\Throwable $error): void
    {
        $owner = $this->executionOwner ?? null;
        if ($owner === null) {
            return;
        }

        DB::transaction(function () use ($owner, $error) {
            $task = BuddyTask::query()->whereKey($this->task->id)->lockForUpdate()->first();
            if ($task === null || $task->operation !== 'council') {
                return;
            }

            $task->runs()->where('run_type', 'council')->where('execution_owner', $owner)->where('status', RunStatus::Started->value)->update([
                'status' => RunStatus::Failed->value,
                'error_class' => $error ? $error::class : null,
                'error_category' => $error ? ErrorClassifier::classify($error)->value : 'transient',
                'completed_at' => now(),
            ]);
            if ($task->claimed_by === $owner && $task->status === TaskStatus::Evaluating) {
                app(TaskStateService::class)->transition($task, TaskStatus::Failed);
            }
        });
    }
}
