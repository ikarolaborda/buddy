<?php

namespace App\Support;

use App\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Exceptions\FailoverableException;

class ErrorClassifier
{
    /**
     * Only transient provider/network/rate-limit failures are worth
     * retrying; validation, auth, and logic errors must fail immediately
     * so the queue does not repeat work that can never succeed.
     */
    public static function classify(\Throwable $e): ErrorClass
    {
        if ($e instanceof ConnectionException || $e instanceof FailoverableException) {
            return ErrorClass::Transient;
        }

        $message = strtolower($e->getMessage());

        foreach (['timeout', 'timed out', 'rate limit', 'too many requests', '429', '503', '502', 'temporarily unavailable', 'connection refused', 'connection reset'] as $marker) {
            if (str_contains($message, $marker)) {
                return ErrorClass::Transient;
            }
        }

        return ErrorClass::Permanent;
    }
}
