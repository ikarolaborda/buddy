<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyTaskEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'buddy_task_id',
        'sequence',
        'type',
        'schema_version',
        'state_version',
        'generation',
        'occurred_at',
        'trace_id',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'schema_version' => 'integer',
            'state_version' => 'integer',
            'generation' => 'integer',
            'occurred_at' => 'datetime',
            'data' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }

    /**
     * The wire envelope (plan §5). Everything a consumer needs to order,
     * deduplicate and authorize is inside; prompts, artifacts, credentials
     * and raw provider errors never are.
     *
     * @return array<string, mixed>
     */
    public function envelope(BuddyTask $task): array
    {
        return [
            'schema_version' => $this->schema_version,
            'event_id' => $this->id,
            'type' => $this->type,
            'client_id' => $task->api_client_id === null ? null : (string) $task->api_client_id,
            'task_id' => $task->ulid,
            'task_sequence' => $this->sequence,
            'state_version' => $this->state_version,
            'generation' => $this->generation,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'trace_id' => $this->trace_id,
            'data' => $this->data,
        ];
    }
}
