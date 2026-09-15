<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuddyDiagnosticCapture extends Model
{
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DENIED = 'denied';

    public const ARTIFACT_KIND = 'diagnostic_capture';

    protected $fillable = [
        'buddy_task_id',
        'api_client_id',
        'request_id',
        'request_hash',
        'target_url',
        'target_host',
        'purpose',
        'policy',
        'capture_seconds',
        'status',
        'callback_token_hash',
        'result',
        'error_code',
        'dispatched_at',
        'completed_at',
    ];

    // Hashes never leave the model, even through toArray() or a log context.
    protected $hidden = [
        'request_hash',
        'callback_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'policy' => 'array',
            'result' => 'array',
            'capture_seconds' => 'integer',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(BuddyTask::class, 'buddy_task_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_DISPATCHED], true);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /*
     * The R2 key is derived from ownership, never chosen by the Worker, so a
     * reported key that differs from this one is rejected at completion.
     */
    public function screenshotObjectKey(): string
    {
        return sprintf(
            'clients/%d/tasks/%s/captures/%s/screenshot.png',
            $this->api_client_id,
            $this->task->ulid,
            $this->id,
        );
    }

    public function artifact(): ?BuddyArtifact
    {
        return $this->task->artifacts()
            ->where('metadata->kind', self::ARTIFACT_KIND)
            ->where('metadata->capture_id', $this->id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function response(): array
    {
        return [
            'capture_id' => $this->id,
            'task_id' => $this->task->ulid,
            'request_id' => $this->request_id,
            'status' => $this->status,
            'target_url' => $this->target_url,
            'target_host' => $this->target_host,
            'purpose' => $this->purpose,
            'policy' => $this->policy,
            'capture_seconds' => $this->capture_seconds,
            'result' => $this->result,
            'error_code' => $this->error_code,
            'dispatched_at' => $this->dispatched_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
