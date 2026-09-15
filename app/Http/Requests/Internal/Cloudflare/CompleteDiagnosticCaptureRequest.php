<?php

namespace App\Http\Requests\Internal\Cloudflare;

use App\Models\BuddyDiagnosticCapture;
use App\Models\BuddyTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteDiagnosticCaptureRequest extends FormRequest
{
    public const CALLBACK_TOKEN = 'callback_token';

    public const STATUS = 'status';

    public const RESULT = 'result';

    public const STATUS_CODE = 'result.status_code';

    public const TIMING_MS = 'result.timing_ms';

    public const CONSOLE_ERRORS = 'result.console_errors';

    public const NETWORK_FAILURES = 'result.network_failures';

    public const SCREENSHOT_OBJECT_KEY = 'result.screenshot_object_key';

    public const ERROR_CODE = 'result.error_code';

    /*
     * The service key opened the prefix; the callback token proves this
     * caller was handed this capture. Both checks answer 404 so a probe
     * learns nothing, and they run before validation for the same reason.
     */
    public function authorize(): bool
    {
        $task = $this->route('task');
        $capture = $this->route('capture');

        if (! $task instanceof BuddyTask || ! $capture instanceof BuddyDiagnosticCapture || $capture->buddy_task_id !== $task->id) {
            abort(404);
        }

        $token = (string) $this->input(self::CALLBACK_TOKEN, '');
        $expected = (string) $capture->callback_token_hash;

        if ($token === '' || $expected === '' || ! hash_equals($expected, hash('sha256', $token))) {
            abort(404);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            self::CALLBACK_TOKEN => ['required', 'string', 'max:256'],
            self::STATUS => ['required', 'string', Rule::in([BuddyDiagnosticCapture::STATUS_COMPLETED, BuddyDiagnosticCapture::STATUS_FAILED])],
            self::RESULT => ['sometimes', 'array'],
            self::STATUS_CODE => ['nullable', 'integer', 'min:100', 'max:599'],
            self::TIMING_MS => ['nullable', 'integer', 'min:0', 'max:3600000'],
            self::CONSOLE_ERRORS => ['sometimes', 'array'],
            self::CONSOLE_ERRORS.'.*' => ['string'],
            self::NETWORK_FAILURES => ['sometimes', 'array'],
            self::NETWORK_FAILURES.'.*' => ['string'],
            self::SCREENSHOT_OBJECT_KEY => ['nullable', 'string', 'max:1024'],
            self::ERROR_CODE => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return (array) ($this->validated()[self::RESULT] ?? []);
    }

    public function status(): string
    {
        return (string) $this->validated()[self::STATUS];
    }
}
