<?php

namespace App\Services\Edge;

use App\Models\BuddyTask;

/*
 * The additive progress representation (plan §7). It is exposed only when the
 * progress flag is on, so legacy responses stay byte-identical with the flag
 * off. Nothing here is authoritative for ownership or claims: it reports
 * timestamps and phase names and never provider identifiers.
 */
final class TaskProgressProjection
{
    /**
     * @return array<string, mixed>
     */
    public static function for(BuddyTask $task): array
    {
        $terminal = $task->isTerminal();

        return [
            'status' => $task->status->value,
            'phase' => $task->phase,
            'phase_started_at' => $task->phase_started_at?->toISOString(),
            'phase_deadline_at' => $task->phase_deadline_at?->toISOString(),
            'queued_at' => $task->queued_at?->toISOString(),
            'worker_started_at' => $task->worker_started_at?->toISOString(),
            'heartbeat_at' => $task->heartbeat_at?->toISOString(),
            'progress_sequence' => (int) $task->progress_sequence,
            'progress_observed_at' => now()->toISOString(),
            'next_poll_after_ms' => $terminal ? 0 : 5000,
            'generation' => (int) $task->generation,
            'state_version' => (int) $task->state_version,
            'recovery_task_id' => $task->recoveryTask?->ulid,
            'failure_category' => $task->failure_category,
        ];
    }
}
