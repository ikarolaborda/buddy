<?php

namespace App\Services\Diagnostics;

use App\Enums\ArtifactType;
use App\Enums\CaptureOutcome;
use App\Jobs\DispatchDiagnosticCaptureJob;
use App\Models\BuddyArtifact;
use App\Models\BuddyDiagnosticCapture;
use App\Models\BuddyTask;
use App\Services\Edge\TaskProgressService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DiagnosticCaptureService
{
    public const DEFAULT_FAILURE_CODE = 'capture_failed';

    public function __construct(
        private CaptureTargetPolicy $policy,
        private CaptureEvidence $evidence,
        private TaskProgressService $progress,
    ) {}

    /**
     * @param  array{request_id: string, url: string, purpose: string, policy: array<string, mixed>, capture_seconds: int}  $args
     */
    public function create(BuddyTask $task, array $args): CaptureCreation
    {
        $hash = $this->hash($args);

        return DB::transaction(function () use ($task, $args, $hash): CaptureCreation {
            $task = BuddyTask::query()->lockForUpdate()->findOrFail($task->id);

            $existing = BuddyDiagnosticCapture::query()
                ->where('buddy_task_id', $task->id)
                ->where('request_id', $args['request_id'])
                ->first();

            if ($existing !== null) {
                return hash_equals($existing->request_hash, $hash)
                    ? new CaptureCreation(CaptureOutcome::Replayed, $existing)
                    : new CaptureCreation(CaptureOutcome::Conflict, $existing);
            }

            $verdict = $this->policy->evaluate($args['url'], $args['policy']);

            if (! $verdict['allowed']) {
                $denied = $this->store($task, $args, $hash, [
                    'target_url' => mb_substr($args['url'], 0, CaptureTargetPolicy::MAX_URL_LENGTH),
                    'target_host' => $verdict['host'],
                    'status' => BuddyDiagnosticCapture::STATUS_DENIED,
                    'error_code' => $verdict['code'],
                    'completed_at' => now(),
                ]);

                return new CaptureCreation(CaptureOutcome::Denied, $denied);
            }

            if ($this->dailyQuotaExhausted((int) $task->api_client_id)) {
                return new CaptureCreation(CaptureOutcome::QuotaExhausted);
            }

            if ($this->capacityExhausted()) {
                return new CaptureCreation(CaptureOutcome::CapacityExhausted);
            }

            $token = bin2hex(random_bytes(32));

            $capture = $this->store($task, $args, $hash, [
                'target_url' => $verdict['normalized_url'],
                'target_host' => $verdict['host'],
                'status' => BuddyDiagnosticCapture::STATUS_QUEUED,
                'callback_token_hash' => hash('sha256', $token),
            ]);

            DispatchDiagnosticCaptureJob::dispatch($capture->id, $token);

            return new CaptureCreation(CaptureOutcome::Queued, $capture);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function matchesCompletion(BuddyDiagnosticCapture $capture, string $status, array $result): bool
    {
        [$bounded, $errorCode] = $this->settlement($status, $result);

        return $capture->status === $status
            && $capture->error_code === $errorCode
            && $capture->result === $bounded;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{capture: BuddyDiagnosticCapture, artifact: BuddyArtifact}
     */
    public function complete(BuddyDiagnosticCapture $capture, string $status, array $result): array
    {
        [$bounded, $errorCode] = $this->settlement($status, $result);

        return DB::transaction(function () use ($capture, $status, $bounded, $errorCode): array {
            $capture = BuddyDiagnosticCapture::query()->lockForUpdate()->findOrFail($capture->id);
            $capture->update([
                'status' => $status,
                'result' => $bounded,
                'error_code' => $errorCode,
                'completed_at' => now(),
            ]);

            $artifact = $capture->task->artifacts()->create([
                'type' => ArtifactType::Other->value,
                'content' => $this->evidence->summary($capture),
                'metadata' => [
                    'kind' => BuddyDiagnosticCapture::ARTIFACT_KIND,
                    'capture_id' => $capture->id,
                    'status' => $status,
                    'target_host' => $capture->target_host,
                    'screenshot_object_key' => $bounded['screenshot_object_key'],
                    'trust' => 'untrusted_page_content',
                ],
            ]);

            $this->progress->record($capture->task, TaskProgressService::TYPE_ARTIFACT_AVAILABLE, [
                'capture_id' => $capture->id,
                'artifact_id' => $artifact->id,
            ]);

            return ['capture' => $capture, 'artifact' => $artifact];
        });
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function settlement(string $status, array $result): array
    {
        $errorCode = $result['error_code'] ?? null;

        if (! is_string($errorCode) || $errorCode === '') {
            $errorCode = $status === BuddyDiagnosticCapture::STATUS_FAILED ? self::DEFAULT_FAILURE_CODE : null;
        }

        return [$this->evidence->bound($result), $errorCode];
    }

    /*
     * Denied captures never reach a browser, so they cost nothing and do not
     * consume the daily allowance; the API rate limit bounds them instead.
     */
    private function dailyQuotaExhausted(int $clientId): bool
    {
        $limit = (int) config('buddy.edge.quotas.captures_per_client_per_day', 10);

        $used = BuddyDiagnosticCapture::query()
            ->where('api_client_id', $clientId)
            ->where('status', '!=', BuddyDiagnosticCapture::STATUS_DENIED)
            ->where('created_at', '>=', Carbon::now('UTC')->startOfDay())
            ->count();

        return $used >= $limit;
    }

    /*
     * Advisory global cap: the task lock does not serialize two different
     * tasks, so two requests in the same instant may both pass. The Worker
     * enforces its own session limit, which is the binding one.
     */
    private function capacityExhausted(): bool
    {
        $limit = (int) config('buddy.edge.quotas.concurrent_captures', 2);

        $open = BuddyDiagnosticCapture::query()
            ->whereIn('status', [BuddyDiagnosticCapture::STATUS_QUEUED, BuddyDiagnosticCapture::STATUS_DISPATCHED])
            ->count();

        return $open >= $limit;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $attributes
     */
    private function store(BuddyTask $task, array $args, string $hash, array $attributes): BuddyDiagnosticCapture
    {
        return BuddyDiagnosticCapture::create($attributes + [
            'buddy_task_id' => $task->id,
            'api_client_id' => $task->api_client_id,
            'request_id' => $args['request_id'],
            'request_hash' => $hash,
            'purpose' => $args['purpose'],
            'policy' => $args['policy'],
            'capture_seconds' => $args['capture_seconds'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function hash(array $args): string
    {
        return hash_hmac('sha256', (string) json_encode($this->canonical($args)), (string) config('app.key'));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
