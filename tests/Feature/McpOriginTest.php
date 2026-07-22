<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpOriginTest extends TestCase
{
    use RefreshDatabase;

    protected string $key;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buddy.api.auth_required' => true]);

        $client = ApiClient::create(['name' => 'origin-test', 'project' => 'buddy']);
        $this->key = app(ApiKeyService::class)
            ->issue($client, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
    }

    public function test_request_without_origin_passes(): void
    {
        $this->withToken($this->key)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertOk();
    }

    public function test_cross_site_origin_is_rejected_before_authentication(): void
    {
        $this->withToken($this->key)
            ->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(403);
    }

    public function test_cross_site_origin_is_rejected_without_key(): void
    {
        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(403);
    }

    public function test_null_origin_is_rejected(): void
    {
        $this->withToken($this->key)
            ->withHeader('Origin', 'null')
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(403);
    }

    public function test_allowlisted_origin_passes(): void
    {
        config(['buddy.api.allowed_origins' => ['http://localhost:6274']]);

        $this->withToken($this->key)
            ->withHeader('Origin', 'http://localhost:6274')
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertOk();
    }

    public function test_get_with_origin_is_rejected(): void
    {
        $this->withHeader('Origin', 'https://evil.example')
            ->get('/api/mcp')
            ->assertStatus(403);
    }
}
