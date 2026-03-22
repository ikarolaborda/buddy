<?php

namespace Tests\Unit;

use App\DTOs\RefinementResult;
use App\Enums\Confidence;
use Tests\TestCase;

class RefinementResultTest extends TestCase
{
    public function test_from_array(): void
    {
        $result = RefinementResult::fromArray([
            'accepted' => true,
            'confidence' => 'high',
            'summary' => 'Refined task into execution brief.',
            'normalized_task' => 'Extract validation logic into FormRequest.',
            'task_intent' => 'refactor',
            'final_execution_prompt' => 'Refactor the validation logic...',
            'clarified_constraints' => ['backward compat'],
            'recommended_tool_sequence' => ['read code', 'create file'],
            'execution_checklist' => ['step 1', 'step 2'],
            'risks' => ['risk 1'],
            'missing_information' => [],
            'verification_plan' => ['run tests'],
            'memory_hits' => ['similar refactor last week'],
        ]);

        $this->assertTrue($result->accepted);
        $this->assertEquals(Confidence::High, $result->confidence);
        $this->assertEquals('refactor', $result->taskIntent);
        $this->assertNotEmpty($result->finalExecutionPrompt);
        $this->assertCount(1, $result->clarifiedConstraints);
        $this->assertCount(2, $result->executionChecklist);
        $this->assertEmpty($result->missingInformation);
    }

    public function test_to_array(): void
    {
        $result = new RefinementResult(
            accepted: true,
            confidence: Confidence::Medium,
            summary: 'Test summary',
            normalizedTask: 'Normalized task',
            taskIntent: 'feature',
            finalExecutionPrompt: 'Do X then Y',
            risks: ['risk A'],
        );

        $array = $result->toArray();

        $this->assertTrue($array['accepted']);
        $this->assertEquals('medium', $array['confidence']);
        $this->assertEquals('Normalized task', $array['normalized_task']);
        $this->assertEquals('feature', $array['task_intent']);
        $this->assertEquals('Do X then Y', $array['final_execution_prompt']);
        $this->assertEquals(['risk A'], $array['risks']);
    }
}
