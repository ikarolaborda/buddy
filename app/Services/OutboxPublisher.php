<?php

namespace App\Services;

use App\Jobs\CouncilDeliberateJob;
use App\Jobs\EvaluateTaskJob;
use App\Jobs\PrefetchEcosystemKnowledgeJob;
use App\Models\BuddyTask;
use App\Models\OutboxMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboxPublisher
{
    public function appendKnowledgePrefetchRequested(BuddyTask $task): ?OutboxMessage
    {
        return $this->append(
            topic: 'buddy.knowledge.prefetch.requested',
            messageKey: $task->ulid,
            payload: ['task_ulid' => $task->ulid],
        );
    }

    /*
     * Append inside the caller's transaction, then publish after commit.
     * The immediate dispatch is the fast path; the relay command is the
     * recovery path that republishes anything a crashed process left
     * unprocessed. Domain truth lives in PostgreSQL either way.
     */
    public function appendTaskSubmitted(BuddyTask $task): ?OutboxMessage
    {
        return $this->append(
            topic: 'buddy.task.submitted',
            messageKey: $task->ulid.':'.$task->operation,
            payload: [
                'task_ulid' => $task->ulid,
                'operation' => $task->operation,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function append(string $topic, string $messageKey, array $payload): ?OutboxMessage
    {
        try {
            $message = OutboxMessage::create([
                'topic' => $topic,
                'message_key' => $messageKey,
                'payload' => $payload,
                'available_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        DB::afterCommit(function () use ($message) {
            $this->publish($message);
        });

        return $message;
    }

    public function publish(OutboxMessage $message): bool
    {
        $claimed = OutboxMessage::query()
            ->whereKey($message->id)
            ->whereNull('processed_at')
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'processed_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            $this->dispatchFor($message);

            return true;
        } catch (\Throwable $e) {
            OutboxMessage::query()
                ->whereKey($message->id)
                ->update([
                    'processed_at' => null,
                    'last_error' => $e->getMessage(),
                ]);

            Log::error('Outbox publish failed', [
                'outbox_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function dispatchFor(OutboxMessage $message): void
    {
        $task = BuddyTask::query()
            ->where('ulid', $message->payload['task_ulid'] ?? null)
            ->first();

        if ($task === null || $task->isTerminal()) {
            return;
        }

        if ($message->topic === 'buddy.knowledge.prefetch.requested') {
            PrefetchEcosystemKnowledgeJob::dispatch($task);

            return;
        }

        match ($message->payload['operation'] ?? 'evaluate') {
            'council' => CouncilDeliberateJob::dispatch($task),
            default => EvaluateTaskJob::dispatch($task),
        };
    }
}
