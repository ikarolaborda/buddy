<?php

namespace Tests\Unit;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Enums\ProblemType;
use App\Models\BuddyTask;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Tests\TestCase;

/**
 * Laravel\Ai\ObjectSchema always sends strict: true, and OpenAI rejects a strict
 * schema whose `required` omits any declared property with
 * "Invalid schema for response_format 'schema_definition' ... Missing '<key>'".
 * That 400 took out every problem type not routed to the fast model, so the
 * invariant is guarded here rather than discovered in production again.
 */
class StructuredOutputSchemaTest extends TestCase
{
    public function test_evaluator_schema_marks_every_property_required(): void
    {
        $agent = new EvaluatorOptimizerAgent(
            new BuddyTask(['problem_type' => ProblemType::Bug])
        );

        $compiled = (new ObjectSchema(
            $agent->schema(new JsonSchemaTypeFactory)
        ))->toArray();

        $properties = array_keys($compiled['properties'] ?? []);
        $required = $compiled['required'] ?? [];

        $this->assertNotEmpty($properties, 'The evaluator schema declared no properties.');

        $this->assertSame(
            [],
            array_values(array_diff($properties, $required)),
            'Every property must appear in `required` or OpenAI rejects the strict schema with a 400.'
        );
    }

    public function test_evaluator_schema_is_sent_as_strict(): void
    {
        $agent = new EvaluatorOptimizerAgent(
            new BuddyTask(['problem_type' => ProblemType::Bug])
        );

        $compiled = (new ObjectSchema(
            $agent->schema(new JsonSchemaTypeFactory)
        ))->toArray();

        $this->assertFalse(
            $compiled['additionalProperties'] ?? true,
            'Strict structured output requires additionalProperties to be false.'
        );
    }
}
