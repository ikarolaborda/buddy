<?php

namespace Tests\Unit;

use Tests\TestCase;

class AzureProviderDeploymentTest extends TestCase
{
    public function test_container_apps_pin_azure_sol_without_direct_openai_credentials(): void
    {
        $api = file_get_contents(base_path('infra/azure/modules/buddy-api.bicep'));
        $worker = file_get_contents(base_path('infra/azure/modules/buddy-worker.bicep'));

        foreach ([$api, $worker] as $template) {
            $this->assertStringContainsString("activeRevisionsMode: 'Single'", $template);
            $this->assertStringContainsString("{ name: 'BUDDY_EVALUATOR_PROVIDER', value: 'azure' }", $template);
            $this->assertStringContainsString("{ name: 'BUDDY_REFINER_PROVIDER', value: 'azure' }", $template);
            $this->assertStringContainsString("{ name: 'BUDDY_MODEL', value: 'gpt-5.6-sol' }", $template);
            $this->assertStringContainsString("{ name: 'BUDDY_MODEL_ROUTING', value: 'false' }", $template);
            $this->assertStringContainsString("{ name: 'AZURE_OPENAI_API_KEY', secretRef: 'azure-openai-key' }", $template);
            $this->assertStringNotContainsString("name: 'OPENAI_API_KEY'", $template);
            $this->assertStringNotContainsString("name: 'openai-key'", $template);
        }
    }

    public function test_worker_retains_openrouter_only_for_the_council(): void
    {
        $worker = file_get_contents(base_path('infra/azure/modules/buddy-worker.bicep'));

        $this->assertStringContainsString(
            "{ name: 'OPENROUTER_API_KEY', secretRef: 'openrouter-api-key' }",
            $worker,
        );
    }
}
