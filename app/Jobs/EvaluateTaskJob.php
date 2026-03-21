<?php

namespace App\Jobs;

use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

#[Tries(1)]
#[Timeout(180)]
#[FailOnTimeout]
class EvaluateTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected BuddyTask $task,
    ) {
        $this->afterCommit();
    }

    public function handle(EvaluatorOptimizerService $evaluator): void
    {
        if ($this->task->isTerminal()) {
            Log::info('Skipping evaluation for terminal task', [
                'task_ulid' => $this->task->ulid,
                'status' => $this->task->status->value,
            ]);

            return;
        }

        try {
            $evaluator->evaluate($this->task);
        } catch (\Throwable $e) {
            Log::error('Async evaluation failed', [
                'task_ulid' => $this->task->ulid,
                'error' => $e->getMessage(),
            ]);

            // Task status is already set to 'failed' by the service
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(): array
    {
        return [
            'buddy_task:'.$this->task->ulid,
            'problem_type:'.$this->task->problem_type->value,
        ];
    }
}
