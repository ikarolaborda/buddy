<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoteMcpTest extends TestCase
{
    use RefreshDatabase;

    protected string $key;

    protected ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buddy.api.auth_required' => true]);

        $this->client = ApiClient::create(['name' => 'remote-agent', 'project' => 'buddy']);
        $this->key = app(ApiKeyService::class)
            ->issue($this->client, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
    }

    protected function rpc(array $message, ?string $key = null)
    {
        return $this->withToken($key ?? $this->key)->postJson('/api/mcp', $message);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(401);
    }

    public function test_it_negotiates_initialize(): void
    {
        $this->rpc([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => []],
        ])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'buddy');
    }

    public function test_ping_returns_empty_result(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])
            ->assertOk()
            ->assertJson(['id' => 2, 'result' => []]);
    }

    public function test_notifications_return_202_without_body(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202);
    }

    public function test_get_returns_405(): void
    {
        $this->get('/api/mcp')->assertStatus(405);
    }

    public function test_tools_list_exposes_six_tools(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);

        $response->assertOk();
        $this->assertCount(6, $response->json('result.tools'));
    }

    public function test_submit_and_status_round_trip_with_client_attribution(): void
    {
        $submit = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.submit_problem', 'arguments' => [
                'source_agent' => 'remote-e2e',
                'task_summary' => 'Remote MCP round trip',
                'problem_type' => 'other',
            ]],
        ]);

        $submit->assertOk();
        $payload = json_decode($submit->json('result.content.0.text'), true);
        $this->assertArrayHasKey('task_id', $payload);

        $this->assertDatabaseHas('buddy_tasks', [
            'ulid' => $payload['task_id'],
            'api_client_id' => $this->client->id,
        ]);

        $status = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.get_task_status', 'arguments' => ['task_id' => $payload['task_id']]],
        ]);

        $status->assertOk();
        $statusPayload = json_decode($status->json('result.content.0.text'), true);
        $this->assertSame('pending', $statusPayload['status']);
    }

    public function test_cross_client_tasks_are_invisible(): void
    {
        $other = ApiClient::create(['name' => 'other', 'project' => 'buddy']);
        $task = BuddyTask::factory()->create(['api_client_id' => $other->id]);

        $response = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.get_task_status', 'arguments' => ['task_id' => $task->ulid]],
        ]);

        $response->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('not found', $response->json('result.content.0.text'));
    }

    public function test_write_tools_require_write_scope(): void
    {
        $readOnly = app(ApiKeyService::class)->issue($this->client, [ApiScope::TasksRead])['plaintext'];

        $response = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.submit_problem', 'arguments' => [
                'source_agent' => 'x', 'task_summary' => 'y', 'problem_type' => 'other',
            ]],
        ], $readOnly);

        $response->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('Insufficient scope', $response->json('result.content.0.text'));
    }

    public function test_malformed_body_returns_parse_error(): void
    {
        $this->rpc(['not' => 'jsonrpc'])->assertStatus(400);
    }
}
