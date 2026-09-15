<?php

namespace App\Http\Requests\Buddy;

use App\Http\Controllers\Concerns\AuthorizesTaskAccess;
use App\Models\BuddyTask;
use App\Services\Diagnostics\CaptureTargetPolicy;
use Illuminate\Foundation\Http\FormRequest;

class CreateDiagnosticCaptureRequest extends FormRequest
{
    use AuthorizesTaskAccess;

    public const REQUEST_ID = 'request_id';

    public const URL = 'url';

    public const PURPOSE = 'purpose';

    public const ALLOWED_HOSTS = 'allowed_hosts';

    public const ALLOW_SUBRESOURCES_SAME_HOST = 'allow_subresources_same_host';

    public const REDIRECTS_ALLOWED = 'redirects_allowed';

    public const CAPTURE_SECONDS = 'capture_seconds';

    public const MAX_ALLOWED_HOSTS = 5;

    public const MIN_CAPTURE_SECONDS = 5;

    // Transport bound only; the policy owns the 2048-character target limit
    // and records the refusal, so a long URL still yields a stable code.
    public const MAX_URL_BYTES = 8192;

    /*
     * Ownership is checked before validation so a validation error can never
     * tell a non-owner that the task exists.
     */
    public function authorize(): bool
    {
        $task = $this->route('task');

        if ($task instanceof BuddyTask) {
            $this->authorizeTaskAccess($this, $task);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            self::REQUEST_ID => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            self::URL => ['required', 'string', 'max:'.self::MAX_URL_BYTES],
            self::PURPOSE => ['required', 'string', 'max:500'],
            self::ALLOWED_HOSTS => ['required', 'array', 'min:1', 'max:'.self::MAX_ALLOWED_HOSTS],
            self::ALLOWED_HOSTS.'.*' => ['required', 'string', 'max:253', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9.-]+$/'],
            self::ALLOW_SUBRESOURCES_SAME_HOST => ['required', 'boolean'],
            self::REDIRECTS_ALLOWED => ['required', 'boolean'],
            self::CAPTURE_SECONDS => ['sometimes', 'integer', 'min:'.self::MIN_CAPTURE_SECONDS, 'max:'.$this->maxCaptureSeconds()],
        ];
    }

    /**
     * Normalized arguments: hosts are lowercased, deduplicated and sorted so
     * the idempotency hash ignores order and case.
     *
     * @return array{request_id: string, url: string, purpose: string, policy: array<string, mixed>, capture_seconds: int}
     */
    public function arguments(CaptureTargetPolicy $policy): array
    {
        $validated = $this->validated();

        return [
            'request_id' => $validated[self::REQUEST_ID],
            'url' => trim($validated[self::URL]),
            'purpose' => trim($validated[self::PURPOSE]),
            'policy' => [
                'allowed_hosts' => $policy->normalizeHosts($validated[self::ALLOWED_HOSTS]),
                'allow_subresources_same_host' => (bool) $validated[self::ALLOW_SUBRESOURCES_SAME_HOST],
                'redirects_allowed' => (bool) $validated[self::REDIRECTS_ALLOWED],
            ],
            'capture_seconds' => (int) ($validated[self::CAPTURE_SECONDS] ?? $this->maxCaptureSeconds()),
        ];
    }

    private function maxCaptureSeconds(): int
    {
        return max(self::MIN_CAPTURE_SECONDS, (int) config('buddy.edge.quotas.capture_seconds', 30));
    }
}
