<?php

namespace App\Contracts;

use App\Exceptions\BrowserDiagnosticsDisabledException;
use App\Models\BuddyDiagnosticCapture;

interface BrowserCaptureDispatcher
{
    /**
     * Hand a queued capture to the browser runtime. Throws
     * BrowserDiagnosticsDisabledException when the runtime reports the live
     * flag off; any other throwable means nothing was started and the
     * dispatch may be retried.
     *
     * @throws BrowserDiagnosticsDisabledException
     */
    public function dispatch(BuddyDiagnosticCapture $capture, #[\SensitiveParameter] string $callbackToken): void;
}
