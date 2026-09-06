<?php

namespace App\Support;

use App\Enums\ErrorClass;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Exceptions\FailoverableException;

class ErrorClassifier
{
    public static function classify(\Throwable $e): ErrorClass
    {
        if ($e instanceof ConnectionException || $e instanceof FailoverableException) {
            return ErrorClass::Transient;
        }

        $message = strtolower($e->getMessage());

        /*
         * 500 and 504 were missing here while 502 and 503 were present, which
         * is the wrong side of the line to be inconsistent on: Azure OpenAI's
         * own 500 body says "You can retry your request", so the provider is
         * telling us it is retryable and we were classifying it Permanent and
         * calling fail() on the first occurrence. Matched as "status code 5xx"
         * rather than the bare number, because a bare "500" also appears in
         * token counts and byte sizes inside otherwise permanent messages.
         */
        $markers = [
            'timeout',
            'timed out',
            'rate limit',
            'too many requests',
            '429',
            'status code 500',
            'status code 502',
            'status code 503',
            'status code 504',
            '502',
            '503',
            'server had an error',
            'temporarily unavailable',
            'service unavailable',
            'connection refused',
            'connection reset',
        ];

        foreach ($markers as $marker) {
            if (str_contains($message, $marker)) {
                return ErrorClass::Transient;
            }
        }

        return ErrorClass::Permanent;
    }
}
