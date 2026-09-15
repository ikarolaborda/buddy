<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapabilitiesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_only_non_sensitive_metadata_and_reflects_flags(): void
    {
        $response = $this->getJson('/api/buddy/capabilities')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('schema_version', 1)
            ->assertJsonPath('features.artifacts', false)
            ->assertJsonPath('features.browser_diagnostics', false)
            ->assertJsonPath('limits.upload_bytes', 26214400)
            ->assertJsonPath('limits.council_artifact_chars', 16000);

        $this->assertStringNotContainsString('key', strtolower(json_encode(array_keys($response->json()))));

        config(['buddy.edge.artifacts' => true]);
        $this->getJson('/api/buddy/capabilities')->assertJsonPath('features.artifacts', true);
    }
}
