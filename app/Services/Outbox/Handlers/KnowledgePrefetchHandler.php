<?php

namespace App\Services\Outbox\Handlers;

use App\Contracts\OutboxHandler;
use App\Jobs\PrefetchEcosystemKnowledgeJob;
use App\Models\BuddyTask;
use App\Models\OutboxMessage;

final class KnowledgePrefetchHandler implements OutboxHandler
{
    public function handle(OutboxMessage $message): void
    {
        $task = BuddyTask::query()
            ->where('ulid', $message->payload['task_ulid'] ?? null)
            ->first();

        if ($task === null || $task->isTerminal()) {
            return;
        }

        PrefetchEcosystemKnowledgeJob::dispatch($task);
    }
}
