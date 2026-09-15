<?php

namespace App\Services\Diagnostics;

use App\Contracts\BrowserCaptureDispatcher;
use App\Exceptions\BrowserDiagnosticsDisabledException;
use App\Models\BuddyDiagnosticCapture;
use Illuminate\Support\Facades\Http;

/*
 * Starts a capture on the Cloudflare Worker, which owns the Browser Run
 * session and reports back through the completion callback. The callback
 * token travels only in this request body and is never echoed into an
 * exception message or a log line.
 */
final class HttpWorkerCaptureDispatcher implements BrowserCaptureDispatcher
{
    public const PATH = '/internal/captures';

    public const TIMEOUT_SECONDS = 10;

    public function dispatch(BuddyDiagnosticCapture $capture, #[\SensitiveParameter] string $callbackToken): void
    {
        $base = rtrim((string) config('buddy.edge.worker_url'), '/');
        $key = (string) config('buddy.edge.service_key');

        if ($base === '' || $key === '') {
            throw new \RuntimeException('Edge worker URL or service key is not configured.');
        }

        $response = Http::withHeaders(['X-Buddy-Edge-Key' => $key])
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($base.self::PATH, $this->payload($capture, $callbackToken));

        if ($response->status() === 503 && $response->json('error') === BrowserDiagnosticsDisabledException::ERROR_CODE) {
            throw new BrowserDiagnosticsDisabledException('The Worker reports browser diagnostics disabled.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Worker refused capture dispatch with HTTP '.$response->status().'.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BuddyDiagnosticCapture $capture, #[\SensitiveParameter] string $callbackToken): array
    {
        $policy = (array) $capture->policy;

        return [
            'capture_id' => $capture->id,
            'task_id' => $capture->task->ulid,
            'client_id' => (string) $capture->api_client_id,
            'url' => $capture->target_url,
            'policy' => [
                'allowed_hosts' => array_values((array) ($policy['allowed_hosts'] ?? [])),
                'allow_subresources_same_host' => (bool) ($policy['allow_subresources_same_host'] ?? false),
                'redirects_allowed' => (bool) ($policy['redirects_allowed'] ?? false),
            ],
            'capture_seconds' => $capture->capture_seconds,
            'screenshot_key' => $capture->screenshotObjectKey(),
            'callback_token' => $callbackToken,
        ];
    }
}
