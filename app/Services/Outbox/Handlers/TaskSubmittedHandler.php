<?php

namespace App\Services\Outbox\Handlers;

use App\Contracts\OutboxHandler;
use App\Jobs\CouncilDeliberateJob;
use App\Jobs\EvaluateTaskJob;
use App\Models\BuddyTask;
use App\Models\OutboxMessage;

final class TaskSubmittedHandler implements OutboxHandler
{
    public function handle(OutboxMessage $message): void
    {
        $task = BuddyTask::query()
            ->where('ulid', $message->payload['task_ulid'] ?? null)
            ->first();

        // A submission for a task that already finished is stale, not an
        // error: the relay can replay it after a crash and must not restart
        // work that completed in between.
        if ($task === null || $task->isTerminal()) {
            return;
        }

        match ($message->payload['operation'] ?? 'evaluate') {
            'council' => CouncilDeliberateJob::dispatch($task),
            default => EvaluateTaskJob::dispatch($task),
        };
    }
}
