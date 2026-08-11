<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Mcp\ProtocolVersions;
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

    protected function modernMessage(array $message): array
    {
        $message['params'] ??= [];
        $message['params']['_meta'] ??= [
            'io.modelcontextprotocol/protocolVersion' => ProtocolVersions::LATEST,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'remote-agent', 'version' => 'test'],
        ];

        return $message;
    }

    protected function modernRpc(array $message, ?string $key = null, array $headers = [])
    {
        $message = $this->modernMessage($message);
        $method = (string) ($message['method'] ?? '');
        $defaults = [
            'MCP-Protocol-Version' => ProtocolVersions::LATEST,
            'Mcp-Method' => $method,
        ];

        if (in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true)) {
            $field = $method === 'resources/read' ? 'uri' : 'name';
            $defaults['Mcp-Name'] = (string) ($message['params'][$field] ?? '');
        }

        return $this->withToken($key ?? $this->key)
            ->withHeaders(array_merge($defaults, $headers))
            ->postJson('/api/mcp', $message);
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

    public function test_modern_discovery_succeeds_without_initialize(): void
    {
        $response = $this->modernRpc(['jsonrpc' => '2.0', 'id' => 'discover-1', 'method' => 'server/discover']);

        $response
            ->assertOk()
            ->assertJsonPath('result.resultType', 'complete')
            ->assertJsonPath('result.supportedVersions.0', ProtocolVersions::LATEST)
            ->assertJsonPath('result.capabilities.tools', [])
            ->assertJsonPath('result.ttlMs', 3600000)
            ->assertJsonPath('result.cacheScope', 'public');

        $this->assertSame('buddy', $response->json()['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
    }

    public function test_modern_results_include_result_type_server_info_and_cache_metadata(): void
    {
        $response = $this->modernRpc(['jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/list']);

        $response
            ->assertOk()
            ->assertJsonPath('result.resultType', 'complete')
            ->assertJsonPath('result.ttlMs', 3600000)
            ->assertJsonPath('result.cacheScope', 'public');

        $this->assertSame('2.0.0', $response->json()['result']['_meta']['io.modelcontextprotocol/serverInfo']['version']);
    }

    public function test_modern_tools_call_decodes_a_base64_name_header_before_validating_it(): void
    {
        $name = 'buddy.get_task_status';

        $this->modernRpc([
            'jsonrpc' => '2.0', 'id' => 22, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => ['task_id' => '01J00000000000000000000000']],
        ], headers: ['Mcp-Name' => '=?base64?'.base64_encode($name).'?='])
            ->assertOk()
            ->assertJsonPath('result.resultType', 'complete')
            ->assertJsonPath('result.isError', true);
    }

    public function test_modern_requests_reject_missing_or_mismatched_routing_headers(): void
    {
        $message = $this->modernMessage(['jsonrpc' => '2.0', 'id' => 23, 'method' => 'ping']);

        $this->withToken($this->key)
            ->withHeaders(['Mcp-Method' => 'ping'])
            ->postJson('/api/mcp', $message)
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32020);

        $this->modernRpc(['jsonrpc' => '2.0', 'id' => 24, 'method' => 'ping'], headers: ['Mcp-Method' => 'tools/list'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32020);
    }

    public function test_modern_requests_reject_missing_required_meta_and_unsupported_versions(): void
    {
        $this->withToken($this->key)
            ->withHeaders([
                'MCP-Protocol-Version' => ProtocolVersions::LATEST,
                'Mcp-Method' => 'ping',
            ])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 25, 'method' => 'ping'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32602);

        $unsupported = $this->modernMessage(['jsonrpc' => '2.0', 'id' => 26, 'method' => 'ping']);
        $unsupported['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '2099-01-01';

        $this->withToken($this->key)
            ->withHeaders([
                'MCP-Protocol-Version' => '2099-01-01',
                'Mcp-Method' => 'ping',
            ])
            ->postJson('/api/mcp', $unsupported)
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32022)
            ->assertJsonPath('error.data.supportedVersions.0', ProtocolVersions::LATEST);
    }

    public function test_unknown_modern_methods_return_not_found(): void
    {
        $this->modernRpc(['jsonrpc' => '2.0', 'id' => 27, 'method' => 'sampling/createMessage'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', -32601);
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

    public function test_tools_list_exposes_seven_tools(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);

        $response->assertOk();
        $this->assertCount(7, $response->json('result.tools'));
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

    public function test_requested_outcome_at_the_contract_limit_is_persisted(): void
    {
        $outcome = str_repeat('a', 2000);

        $submit = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 40, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.submit_problem', 'arguments' => [
                'source_agent' => 'remote-e2e',
                'task_summary' => 'Long requested outcome',
                'problem_type' => 'other',
                'requested_outcome' => $outcome,
            ]],
        ]);

        $submit->assertOk();
        $payload = json_decode($submit->json('result.content.0.text'), true);
        $this->assertArrayHasKey('task_id', $payload, 'submit_problem must accept a 2000-char requested_outcome');

        $task = BuddyTask::where('ulid', $payload['task_id'])->firstOrFail();
        $this->assertSame($outcome, $task->requested_outcome, 'the stored value must not be truncated');
    }

    public function test_oversized_requested_outcome_fails_validation_instead_of_erroring_opaquely(): void
    {
        $response = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 41, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.submit_problem', 'arguments' => [
                'source_agent' => 'remote-e2e',
                'task_summary' => 'Oversized requested outcome',
                'problem_type' => 'other',
                'requested_outcome' => str_repeat('a', 2001),
            ]],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('Validation failed', $response->json('result.content.0.text'));
    }

    public function test_unknown_problem_type_fails_validation_instead_of_erroring_opaquely(): void
    {
        $response = $this->rpc([
            'jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.submit_problem', 'arguments' => [
                'source_agent' => 'remote-e2e',
                'task_summary' => 'Unknown problem type',
                'problem_type' => 'not_a_real_problem_type',
            ]],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('Validation failed', $response->json('result.content.0.text'));
    }
}
