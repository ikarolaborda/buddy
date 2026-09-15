<?php

namespace App\Jobs;

use App\Contracts\BrowserCaptureDispatcher;
use App\Exceptions\BrowserDiagnosticsDisabledException;
use App\Models\BuddyDiagnosticCapture;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/*
 * Carries the one-time callback token from the request transaction to the
 * Worker. The token exists in clear only inside this payload, which is why
 * the job is encrypted at rest on the queue and in failed_jobs, and why the
 * record keeps a hash alone.
 */
#[Tries(DispatchDiagnosticCaptureJob::MAX_ATTEMPTS)]
#[Timeout(30)]
class DispatchDiagnosticCaptureJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_ATTEMPTS = 2;

    public const RETRY_DELAY_SECONDS = 5;

    public const ERROR_DISPATCH_FAILED = 'dispatch_failed';

    public function __construct(
        public readonly string $captureId,
        #[\SensitiveParameter] private readonly string $callbackToken,
    ) {
        $this->onQueue((string) config('buddy.queues.lanes.fast'));
        $this->afterCommit();
    }

    public function handle(BrowserCaptureDispatcher $dispatcher): void
    {
        $capture = BuddyDiagnosticCapture::query()->find($this->captureId);

        if ($capture === null || $capture->status !== BuddyDiagnosticCapture::STATUS_QUEUED) {
            return;
        }

        try {
            $dispatcher->dispatch($capture, $this->callbackToken);
        } catch (BrowserDiagnosticsDisabledException) {
            $this->settle($capture, BrowserDiagnosticsDisabledException::ERROR_CODE);

            return;
        } catch (\Throwable $exception) {
            Log::warning('Diagnostic capture dispatch failed', [
                'capture_id' => $capture->id,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            if ($this->attempts() >= self::MAX_ATTEMPTS) {
                $this->settle($capture, self::ERROR_DISPATCH_FAILED);

                return;
            }

            $this->release(self::RETRY_DELAY_SECONDS);

            return;
        }

        $capture->update([
            'status' => BuddyDiagnosticCapture::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ]);
    }

    /*
     * Backstop for the paths handle() cannot see, such as a timeout that
     * exhausts the attempts: a capture must never stay queued forever.
     */
    public function failed(?\Throwable $exception = null): void
    {
        $capture = BuddyDiagnosticCapture::query()->find($this->captureId);

        if ($capture === null || $capture->status !== BuddyDiagnosticCapture::STATUS_QUEUED) {
            return;
        }

        $this->settle($capture, self::ERROR_DISPATCH_FAILED);
    }

    private function settle(BuddyDiagnosticCapture $capture, string $errorCode): void
    {
        $capture->update([
            'status' => BuddyDiagnosticCapture::STATUS_FAILED,
            'error_code' => $errorCode,
            'completed_at' => now(),
        ]);
    }
}
