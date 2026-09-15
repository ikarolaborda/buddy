<?php

namespace App\Enums;

enum CaptureOutcome: string
{
    case Queued = 'queued';
    case Replayed = 'replayed';
    case Denied = 'denied';
    case Conflict = 'conflict';
    case QuotaExhausted = 'quota_exhausted';
    case CapacityExhausted = 'capacity_exhausted';
}
