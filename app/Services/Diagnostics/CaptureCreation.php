<?php

namespace App\Services\Diagnostics;

use App\Enums\CaptureOutcome;
use App\Models\BuddyDiagnosticCapture;

final readonly class CaptureCreation
{
    public function __construct(
        public CaptureOutcome $outcome,
        public ?BuddyDiagnosticCapture $capture = null,
    ) {}
}
