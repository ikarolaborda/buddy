<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    protected ApiClient $client;

    protected string $plaintext;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buddy.api.auth_required' => true]);

        $this->client = ApiClient::create(['name' => 'test-agent', 'project' => 'buddy']);

        $issued = app(ApiKeyService::class)->issue($this->client, [ApiScope::TasksRead, ApiScope::TasksWrite]);
        $this->plaintext = $issued['plaintext'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function taskPayload(): array
    {
        return [
            'source_agent' => 'claude',
            'task_summary' => 'Login page returns 500 after OAuth callback',
            'problem_type' => 'bug',
        ];
    }

    public function test_it_rejects_requests_without_api_key(): void
    {
        $response = $this->postJson('/api/buddy/tasks', $this->taskPayload());

        $response->assertStatus(401);
    }

    public function test_it_rejects_malformed_bearer_tokens(): void
    {
        $response = $this->withToken('bdy_live_invalid')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $response->assertStatus(401);
    }

    public function test_it_accepts_a_valid_api_key(): void
    {
        $response = $this->withToken($this->plaintext)
            ->withHeader('Idempotency-Key', 'key-1')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $response->assertStatus(201);

        $this->assertDatabaseHas('buddy_tasks', [
            'api_client_id' => $this->client->id,
        ]);
    }

    public function test_it_rejects_a_revoked_key(): void
    {
        $key = $this->client->apiKeys()->first();
        app(ApiKeyService::class)->revoke($key, 'test');

        $response = $this->withToken($this->plaintext)
            ->withHeader('Idempotency-Key', 'key-2')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $response->assertStatus(401);
    }

    public function test_it_rejects_an_expired_key(): void
    {
        $issued = app(ApiKeyService::class)->issue(
            $this->client,
            [ApiScope::TasksWrite],
            now()->subMinute(),
        );

        $response = $this->withToken($issued['plaintext'])
            ->withHeader('Idempotency-Key', 'key-3')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $response->assertStatus(401);
    }

    public function test_it_enforces_scopes(): void
    {
        $issued = app(ApiKeyService::class)->issue($this->client, [ApiScope::TasksRead]);

        $response = $this->withToken($issued['plaintext'])
            ->withHeader('Idempotency-Key', 'key-4')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $response->assertStatus(403);
    }

    public function test_it_requires_an_idempotency_key_for_submission(): void
    {
        $response = $this->withToken($this->plaintext)
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $response->assertStatus(422);
    }

    public function test_it_replays_duplicate_idempotent_submissions(): void
    {
        $first = $this->withToken($this->plaintext)
            ->withHeader('Idempotency-Key', 'dup-key')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $second = $this->withToken($this->plaintext)
            ->withHeader('Idempotency-Key', 'dup-key')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $first->assertStatus(201);
        $second->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(
            $first->json('data.task_id'),
            $second->json('data.task_id'),
        );

        $this->assertSame(1, BuddyTask::count());
    }

    public function test_it_rejects_idempotency_key_reuse_with_different_payload(): void
    {
        $this->withToken($this->plaintext)
            ->withHeader('Idempotency-Key', 'reuse-key')
            ->postJson('/api/buddy/tasks', $this->taskPayload());

        $response = $this->withToken($this->plaintext)
            ->withHeader('Idempotency-Key', 'reuse-key')
            ->postJson('/api/buddy/tasks', array_merge($this->taskPayload(), [
                'task_summary' => 'A completely different problem',
            ]));

        $response->assertStatus(409);
    }
}
