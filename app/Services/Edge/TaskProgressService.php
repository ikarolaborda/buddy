<?php

namespace App\Services\Edge;

use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Services\OutboxPublisher;
use Illuminate\Support\Facades\DB;

/*
 * Bounded lifecycle facts for the live dashboard and the supervisor (plan §7).
 * Every record() call must sit inside the transaction that commits the durable
 * change it reports, so an aborted transaction leaves neither an event nor an
 * outbox intent. The task row is locked to allocate the next sequence and the
 * unique (task, sequence) index is the final guard against two writers.
 */
class TaskProgressService
{
    public const TYPE_PROGRESS = 'buddy.task.progress.v1';

    public const TYPE_TERMINAL = 'buddy.task.terminal.v1';

    public const TYPE_RECOVERY = 'buddy.task.recovery.v1';

    public const TYPE_ARTIFACT_AVAILABLE = 'buddy.task.artifact.available.v1';

    public const TYPE_ARTIFACT_PROCESSED = 'buddy.task.artifact.processed.v1';

    public const TYPE_EXPORT_COMPLETED = 'buddy.task.export.completed.v1';

    public function __construct(
        protected OutboxPublisher $outbox,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('buddy.edge.events') || (bool) config('buddy.edge.progress');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function phase(BuddyTask $task, string $phase, array $data = [], ?int $deadlineSeconds = null): ?BuddyTaskEvent
    {
        return $this->record($task, self::TYPE_PROGRESS, ['phase' => $phase] + $data, phase: $phase, deadlineSeconds: $deadlineSeconds);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function terminal(BuddyTask $task, array $data = []): ?BuddyTaskEvent
    {
        return $this->record($task, self::TYPE_TERMINAL, ['status' => $task->status->value] + $data, phase: 'terminal');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(
        BuddyTask $task,
        string $type,
        array $data = [],
        ?string $phase = null,
        ?int $deadlineSeconds = null,
        ?string $traceId = null,
    ): ?BuddyTaskEvent {
        if (! $this->enabled()) {
            return null;
        }

        $data = $this->bound($data);

        return DB::transaction(function () use ($task, $type, $data, $phase, $deadlineSeconds, $traceId) {
            $locked = BuddyTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $sequence = (int) $locked->progress_sequence + 1;

            $update = ['progress_sequence' => $sequence];

            if ($phase !== null && $phase !== $locked->phase) {
                $update['phase'] = $phase;
                $update['phase_started_at'] = now();
                $update['phase_deadline_at'] = $deadlineSeconds === null ? null : now()->addSeconds($deadlineSeconds);
            }

            BuddyTask::query()->whereKey($locked->id)->update($update);

            $event = BuddyTaskEvent::create([
                'buddy_task_id' => $locked->id,
                'sequence' => $sequence,
                'type' => $type,
                'schema_version' => (int) config('buddy.edge.schema_version', 1),
                'state_version' => (int) $locked->state_version,
                'generation' => (int) $locked->generation,
                'occurred_at' => now(),
                'trace_id' => $traceId,
                'data' => $data,
            ]);

            if (config('buddy.edge.events')) {
                $this->outbox->appendTaskEvent($locked, $event);
            }

            $task->progress_sequence = $sequence;

            foreach ($update as $column => $value) {
                $task->{$column} = $value;
            }

            return $event;
        });
    }

    /**
     * Events are capped at 16 KiB by policy (plan §5). Anything larger is
     * replaced by a marker so a consumer sees that content was withheld
     * rather than receiving a truncated JSON document.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function bound(array $data): array
    {
        $limit = (int) config('buddy.edge.event_max_bytes', 16384);
        $encoded = (string) json_encode($data);

        if (strlen($encoded) <= $limit) {
            return $data;
        }

        return [
            'withheld' => true,
            'reason' => 'event exceeded '.$limit.' bytes',
            'keys' => array_keys($data),
        ];
    }
}
