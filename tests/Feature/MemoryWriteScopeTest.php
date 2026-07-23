<?php

namespace Tests\Feature;

use App\Contracts\MemoryGateway;
use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class MemoryWriteScopeTest extends TestCase
{
    use RefreshDatabase;

    protected ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake();
        config(['buddy.api.auth_required' => true]);

        $this->client = ApiClient::create(['name' => 'scoped-agent', 'project' => 'buddy']);
    }

    protected function issueKey(array $scopes): string
    {
        return app(ApiKeyService::class)->issue($this->client, $scopes)['plaintext'];
    }

    public function test_mcp_close_without_memory_write_skips_learnings(): void
    {
        $memory = $this->spy(MemoryGateway::class);
        $key = $this->issueKey([ApiScope::TasksRead, ApiScope::TasksWrite]);
        $task = BuddyTask::factory()->completed()->create(['api_client_id' => $this->client->id]);

        $response = $this->withToken($key)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => [
                'name' => 'buddy.close_task',
                'arguments' => ['task_id' => $task->ulid, 'learnings_summary' => 'A durable lesson.'],
            ],
        ]);

        $response->assertOk();

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('closed', $payload['status']);
        $this->assertFalse($payload['learnings_stored']);
        $memory->shouldNotHaveReceived('store');
    }

    public function test_mcp_close_with_memory_write_stores_learnings(): void
    {
        $memory = $this->spy(MemoryGateway::class);
        $key = $this->issueKey([ApiScope::TasksRead, ApiScope::TasksWrite, ApiScope::MemoryWrite]);
        $task = BuddyTask::factory()->completed()->create(['api_client_id' => $this->client->id]);

        $response = $this->withToken($key)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => [
                'name' => 'buddy.close_task',
                'arguments' => ['task_id' => $task->ulid, 'learnings_summary' => 'A durable lesson.'],
            ],
        ]);

        $response->assertOk();

        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame('closed', $payload['status']);
        $this->assertArrayNotHasKey('learnings_stored', $payload);
        $memory->shouldHaveReceived('store')->once();
    }

    public function test_rest_close_without_memory_write_skips_learnings(): void
    {
        $memory = $this->spy(MemoryGateway::class);
        $key = $this->issueKey([ApiScope::TasksRead, ApiScope::TasksWrite]);
        $task = BuddyTask::factory()->completed()->create(['api_client_id' => $this->client->id]);

        $response = $this->withToken($key)->postJson("/api/buddy/tasks/{$task->ulid}/close", [
            'learnings_summary' => 'A durable lesson.',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('learnings_stored', false);

        $memory->shouldNotHaveReceived('store');
    }

    public function test_rest_close_without_learnings_needs_no_memory_scope(): void
    {
        $key = $this->issueKey([ApiScope::TasksRead, ApiScope::TasksWrite]);
        $task = BuddyTask::factory()->completed()->create(['api_client_id' => $this->client->id]);

        $this->withToken($key)->postJson("/api/buddy/tasks/{$task->ulid}/close", [
            'outcome' => 'resolved',
        ])->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonMissingPath('learnings_stored');
    }

    public function test_migration_grants_memory_write_to_existing_keys(): void
    {
        $key = app(ApiKeyService::class)->issue($this->client, [ApiScope::TasksRead, ApiScope::TasksWrite]);

        $this->assertFalse($key['key']->hasScope(ApiScope::MemoryWrite));

        (include database_path('migrations/2026_07_23_100000_grant_memory_write_to_existing_api_keys.php'))->up();

        $this->assertTrue($key['key']->refresh()->hasScope(ApiScope::MemoryWrite));
    }
}
