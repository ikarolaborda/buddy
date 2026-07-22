<?php

namespace App\Jobs;

use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use App\Services\TaskStateService;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Tries(1): a council bills real money on every attempt; a flaky run
 * must fail loudly and be re-paid deliberately, never retried silently
 * (ADR 0009 cost control). Timeout must stay under the queue
 * retry_after or a still-deliberating council gets redelivered.
 */
#[Tries(1)]
#[Timeout(900)]
#[FailOnTimeout]
class CouncilDeliberateJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected BuddyTask $task,
    ) {
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
                ->expireAfter((int) config('buddy.timeouts.council_lease', 1200))
                ->shared(),
        ];
    }

    public function handle(EvaluatorOptimizerService $evaluator, TaskStateService $state): void
    {
        $this->task->refresh();

        if ($this->task->isTerminal()) {
            Log::info('Skipping council for terminal task', ['task_ulid' => $this->task->ulid]);

            return;
        }

        if (! config('buddy_agents.council.enabled')) {
            Log::warning('Council disabled; task left pending', ['task_ulid' => $this->task->ulid]);

            return;
        }

        $today = BuddyRun::query()
            ->where('run_type', 'council')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($today >= (int) config('buddy_agents.council.max_per_day', 10)) {
            Log::warning('Council daily cap reached', ['task_ulid' => $this->task->ulid, 'today' => $today]);
            $this->fail(new \RuntimeException('Council daily cap reached ('.$today.').'));

            return;
        }

        $owner = gethostname().':'.getmypid().':'.Str::random(6);

        if (! $state->claim($this->task, $owner, (int) config('buddy.timeouts.council_lease', 1200))) {
            Log::info('Council task already claimed', ['task_ulid' => $this->task->ulid]);

            return;
        }

        try {
            $evaluator->council($this->task, $owner);
        } catch (\Throwable $e) {
            $state->release($this->task, $owner);

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
}
