<?php

namespace Tests\Unit;

use App\Enums\ErrorClass;
use App\Support\ErrorClassifier;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;

class ErrorClassifierTest extends TestCase
{
    public function test_it_classifies_connection_failures_as_transient(): void
    {
        $this->assertSame(
            ErrorClass::Transient,
            ErrorClassifier::classify(new ConnectionException('Connection refused')),
        );
    }

    public function test_it_classifies_rate_limits_as_transient(): void
    {
        $this->assertSame(
            ErrorClass::Transient,
            ErrorClassifier::classify(new \RuntimeException('Provider returned 429 Too Many Requests')),
        );
    }

    public function test_it_classifies_timeouts_as_transient(): void
    {
        $this->assertSame(
            ErrorClass::Transient,
            ErrorClassifier::classify(new \RuntimeException('cURL error 28: Connection timed out')),
        );
    }

    public function test_it_classifies_validation_errors_as_permanent(): void
    {
        $this->assertSame(
            ErrorClass::Permanent,
            ErrorClassifier::classify(new \InvalidArgumentException('Invalid schema field')),
        );
    }

    public function test_it_classifies_logic_errors_as_permanent(): void
    {
        $this->assertSame(
            ErrorClass::Permanent,
            ErrorClassifier::classify(new \RuntimeException('Task is already in terminal state')),
        );
    }

    /**
     * 500 and 504 were missing from the transient markers while 502 and 503
     * were present. Azure OpenAI's own 500 body says "You can retry your
     * request", so buddy was calling fail() on the first occurrence of an error
     * the provider had just told it to retry.
     */
    public function test_it_classifies_server_errors_as_transient(): void
    {
        foreach ([
            'HTTP request returned status code 500',
            'HTTP request returned status code 502',
            'HTTP request returned status code 503',
            'HTTP request returned status code 504',
            'The server had an error processing your request. Sorry about that!',
            'Service Unavailable',
        ] as $message) {
            $this->assertSame(
                ErrorClass::Transient,
                ErrorClassifier::classify(new \RuntimeException($message)),
                $message.' should be retryable',
            );
        }
    }

    /**
     * Matched as "status code 5xx" rather than the bare number, because a bare
     * "500" also turns up in token counts and byte sizes inside messages that
     * are genuinely permanent.
     */
    public function test_it_does_not_treat_an_incidental_500_as_a_server_error(): void
    {
        $this->assertSame(
            ErrorClass::Permanent,
            ErrorClassifier::classify(new \InvalidArgumentException('Schema field exceeds the 500 character limit')),
        );
    }
}
