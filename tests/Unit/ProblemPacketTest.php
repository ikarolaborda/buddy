<?php

namespace Tests\Unit;

use App\DTOs\ProblemPacket;
use App\Enums\ProblemType;
use Tests\TestCase;

class ProblemPacketTest extends TestCase
{
    public function test_from_array_with_all_fields(): void
    {
        $packet = ProblemPacket::fromArray([
            'source_agent' => 'claude',
            'task_summary' => 'Login fails after OAuth',
            'problem_type' => 'bug',
            'repo' => 'acme/webapp',
            'branch' => 'main',
            'constraints' => ['no breaking changes'],
            'attempts' => [['action' => 'restarted server']],
            'evidence' => [['type' => 'log', 'content' => 'error at line 42']],
            'artifacts' => [],
            'requested_outcome' => 'Fix the login',
        ]);

        $this->assertEquals('claude', $packet->sourceAgent);
        $this->assertEquals('Login fails after OAuth', $packet->taskSummary);
        $this->assertEquals(ProblemType::Bug, $packet->problemType);
        $this->assertEquals('acme/webapp', $packet->repo);
        $this->assertEquals('main', $packet->branch);
        $this->assertEquals(['no breaking changes'], $packet->constraints);
        $this->assertCount(1, $packet->attempts);
        $this->assertCount(1, $packet->evidence);
        $this->assertEquals('Fix the login', $packet->requestedOutcome);
    }

    public function test_from_array_with_minimal_fields(): void
    {
        $packet = ProblemPacket::fromArray([
            'source_agent' => 'cursor',
            'task_summary' => 'Something broke',
            'problem_type' => 'ambiguous',
        ]);

        $this->assertEquals('cursor', $packet->sourceAgent);
        $this->assertEquals(ProblemType::Ambiguous, $packet->problemType);
        $this->assertNull($packet->repo);
        $this->assertNull($packet->branch);
        $this->assertEmpty($packet->constraints);
        $this->assertEmpty($packet->attempts);
        $this->assertEmpty($packet->evidence);
    }
}
