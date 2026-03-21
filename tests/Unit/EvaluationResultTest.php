<?php

namespace Tests\Unit;

use App\DTOs\EvaluationResult;
use App\Enums\Confidence;
use Tests\TestCase;

class EvaluationResultTest extends TestCase
{
    public function test_from_array_accepted(): void
    {
        $result = EvaluationResult::fromArray([
            'accepted' => true,
            'confidence' => 'high',
            'summary' => 'The fix is straightforward.',
            'recommended_plan' => ['Step 1', 'Step 2'],
            'risks' => ['Low risk'],
        ]);

        $this->assertTrue($result->accepted);
        $this->assertEquals(Confidence::High, $result->confidence);
        $this->assertEquals('The fix is straightforward.', $result->summary);
        $this->assertCount(2, $result->recommendedPlan);
        $this->assertCount(1, $result->risks);
    }

    public function test_from_array_rejected(): void
    {
        $result = EvaluationResult::fromArray([
            'accepted' => false,
            'confidence' => 'low',
            'summary' => 'Insufficient evidence.',
            'rejected_reasons' => ['No stack trace', 'Cannot reproduce'],
            'required_followups' => ['Provide stack trace', 'Share reproduction steps'],
        ]);

        $this->assertFalse($result->accepted);
        $this->assertEquals(Confidence::Low, $result->confidence);
        $this->assertCount(2, $result->rejectedReasons);
        $this->assertCount(2, $result->requiredFollowups);
    }

    public function test_to_array(): void
    {
        $result = new EvaluationResult(
            accepted: true,
            confidence: Confidence::Medium,
            summary: 'Test summary',
            recommendedPlan: ['Do X'],
            risks: ['Risk Y'],
        );

        $array = $result->toArray();

        $this->assertTrue($array['accepted']);
        $this->assertEquals('medium', $array['confidence']);
        $this->assertEquals('Test summary', $array['summary']);
        $this->assertEquals(['Do X'], $array['recommended_plan']);
        $this->assertEquals(['Risk Y'], $array['risks']);
    }
}
