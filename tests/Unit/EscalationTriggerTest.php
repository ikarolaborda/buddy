<?php

namespace Tests\Unit;

use App\DTOs\EscalationTrigger;
use Tests\TestCase;

class EscalationTriggerTest extends TestCase
{
    public function test_should_not_escalate_with_no_conditions(): void
    {
        $trigger = new EscalationTrigger;

        $this->assertFalse($trigger->shouldEscalate());
        $this->assertEquals(0, $trigger->activeConditionCount());
        $this->assertEmpty($trigger->activeConditions());
    }

    public function test_should_not_escalate_with_one_condition(): void
    {
        $trigger = new EscalationTrigger(
            failedAttempts: 5,
        );

        $this->assertFalse($trigger->shouldEscalate());
        $this->assertEquals(1, $trigger->activeConditionCount());
    }

    public function test_should_escalate_with_two_conditions(): void
    {
        $trigger = new EscalationTrigger(
            elapsedSeconds: 600,
            failedAttempts: 5,
        );

        $this->assertTrue($trigger->shouldEscalate());
        $this->assertEquals(2, $trigger->activeConditionCount());
        $this->assertContains('time_elapsed', $trigger->activeConditions());
        $this->assertContains('failed_attempts', $trigger->activeConditions());
    }

    public function test_should_escalate_with_all_conditions(): void
    {
        $trigger = new EscalationTrigger(
            elapsedSeconds: 600,
            failedAttempts: 5,
            repeatedTestFailure: true,
            lowConfidence: true,
            rootCauseAmbiguous: true,
            evidenceConflicts: true,
        );

        $this->assertTrue($trigger->shouldEscalate());
        $this->assertEquals(6, $trigger->activeConditionCount());
    }

    public function test_from_array(): void
    {
        $trigger = EscalationTrigger::fromArray([
            'elapsed_seconds' => 400,
            'failed_attempts' => 3,
            'repeated_test_failure' => true,
        ]);

        $this->assertTrue($trigger->shouldEscalate());
        $this->assertEquals(400, $trigger->elapsedSeconds);
        $this->assertEquals(3, $trigger->failedAttempts);
        $this->assertTrue($trigger->repeatedTestFailure);
    }

    public function test_elapsed_time_threshold(): void
    {
        // 300 is exactly at threshold (not over)
        $trigger = new EscalationTrigger(elapsedSeconds: 300);
        $this->assertEquals(0, $trigger->activeConditionCount());

        // 301 is over threshold
        $trigger = new EscalationTrigger(elapsedSeconds: 301);
        $this->assertEquals(1, $trigger->activeConditionCount());
    }

    public function test_failed_attempts_threshold(): void
    {
        // 2 is exactly at threshold (not over)
        $trigger = new EscalationTrigger(failedAttempts: 2);
        $this->assertEquals(0, $trigger->activeConditionCount());

        // 3 is over threshold
        $trigger = new EscalationTrigger(failedAttempts: 3);
        $this->assertEquals(1, $trigger->activeConditionCount());
    }
}
