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
}
