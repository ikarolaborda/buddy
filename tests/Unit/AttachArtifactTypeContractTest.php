<?php

namespace Tests\Unit;

use App\Enums\ArtifactType;
use App\Mcp\RemoteToolDefinitions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttachArtifactTypeContractTest extends TestCase
{
    /*
     * The advertised schema is the only place a client can learn the allowed
     * values. It shipped as a bare string, so callers guessed 'code' for
     * code_snippet and got an opaque ValueError from the model cast.
     */
    public function test_the_advertised_attach_artifact_schema_lists_every_artifact_type(): void
    {
        $tool = collect((new RemoteToolDefinitions)->all())
            ->firstWhere('name', 'buddy.attach_artifact');

        $this->assertNotNull($tool, 'buddy.attach_artifact is not advertised.');

        $expected = array_map(static fn (ArtifactType $t): string => $t->value, ArtifactType::cases());

        $this->assertSame($expected, $tool['inputSchema']['properties']['type']['enum'] ?? null);
    }

    /*
     * Rule::enum on its own reports only an invalid selection, which leaves the
     * caller guessing a second time. The message has to name the values.
     */
    public function test_an_out_of_range_type_is_rejected_with_the_allowed_values_named(): void
    {
        $allowed = array_map(static fn (ArtifactType $t): string => $t->value, ArtifactType::cases());

        try {
            Validator::validate(
                ['type' => 'code'],
                ['type' => ['required', 'string', Rule::enum(ArtifactType::class)]],
                ['type.enum' => 'The type must be one of: '.implode(', ', $allowed).'.']
            );

            $this->fail('An out-of-range artifact type was accepted.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->validator->errors()->all());

            $this->assertStringContainsString('code_snippet', $message);
            $this->assertStringNotContainsString('validation.enum', $message);
        }
    }
}
