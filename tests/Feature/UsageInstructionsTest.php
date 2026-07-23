<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class UsageInstructionsTest extends TestCase
{
    use RefreshDatabase;

    protected string $key;

    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake();
        config(['buddy.api.auth_required' => true]);

        $client = ApiClient::create(['name' => 'protocol-agent', 'project' => 'buddy']);
        $this->key = app(ApiKeyService::class)
            ->issue($client, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
    }

    public function test_initialize_serves_the_close_protocol_instructions(): void
    {
        $response = $this->withToken($this->key)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => []],
        ]);

        $response->assertOk();

        $instructions = (string) $response->json('result.instructions');

        $this->assertStringContainsString('buddy.close_task', $instructions);
        $this->assertStringContainsString('outcome', $instructions);
        $this->assertStringContainsString('durable memory', $instructions);
    }

    public function test_submit_problem_echoes_the_close_protocol(): void
    {
        $response = $this->withToken($this->key)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => [
                'name' => 'buddy.submit_problem',
                'arguments' => [
                    'source_agent' => 'claude',
                    'task_summary' => 'A failing test',
                    'problem_type' => 'test_failure',
                ],
            ],
        ]);

        $response->assertOk();

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertArrayHasKey('task_id', $payload);
        $this->assertStringContainsString('always pass outcome', $payload['close_protocol']);
    }
}
