<?php

namespace App\Services;

use App\Jobs\EvaluateTaskJob;
use App\Models\BuddyTask;
use App\Models\OutboxMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboxPublisher
{
    /*
     * Append inside the caller's transaction, then publish after commit.
     * The immediate dispatch is the fast path; the relay command is the
     * recovery path that republishes anything a crashed process left
     * unprocessed. Domain truth lives in PostgreSQL either way.
     */
    public function appendTaskSubmitted(BuddyTask $task): ?OutboxMessage
    {
        try {
            $message = OutboxMessage::create([
                'topic' => 'buddy.task.submitted',
                'message_key' => $task->ulid.':'.$task->operation,
                'payload' => [
                    'task_ulid' => $task->ulid,
                    'operation' => $task->operation,
                ],
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

        EvaluateTaskJob::dispatch($task);
    }
}
