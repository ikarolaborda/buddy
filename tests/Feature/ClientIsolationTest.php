<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected string $ownerKey;

    protected string $otherKey;

    protected string $adminKey;

    protected BuddyTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buddy.api.auth_required' => true]);

        $service = app(ApiKeyService::class);
        $owner = ApiClient::create(['name' => 'owner', 'project' => 'buddy']);
        $other = ApiClient::create(['name' => 'other', 'project' => 'buddy']);
        $admin = ApiClient::create(['name' => 'admin', 'project' => 'buddy']);

        $this->ownerKey = $service->issue($owner, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
        $this->otherKey = $service->issue($other, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
        $this->adminKey = $service->issue($admin, [ApiScope::Admin])['plaintext'];

        $this->task = BuddyTask::factory()->create(['api_client_id' => $owner->id]);
    }

    public function test_the_owner_can_read_its_task(): void
    {
        $this->withToken($this->ownerKey)
            ->getJson("/api/buddy/tasks/{$this->task->ulid}")
            ->assertOk();
    }

    public function test_another_client_gets_404_not_403(): void
    {
        $this->withToken($this->otherKey)
            ->getJson("/api/buddy/tasks/{$this->task->ulid}")
            ->assertNotFound();
    }

    public function test_another_client_cannot_mutate_the_task(): void
    {
        $this->withToken($this->otherKey)
            ->postJson("/api/buddy/tasks/{$this->task->ulid}/evaluate")
            ->assertNotFound();

        $this->withToken($this->otherKey)
            ->postJson("/api/buddy/tasks/{$this->task->ulid}/artifacts", ['type' => 'log', 'content' => 'x'])
            ->assertNotFound();

        $this->withToken($this->otherKey)
            ->postJson("/api/buddy/tasks/{$this->task->ulid}/close")
            ->assertNotFound();
    }

    public function test_admin_scope_bypasses_isolation(): void
    {
        $this->withToken($this->adminKey)
            ->getJson("/api/buddy/tasks/{$this->task->ulid}")
            ->assertOk();
    }

    public function test_ownerless_legacy_tasks_stay_readable(): void
    {
        $legacy = BuddyTask::factory()->create(['api_client_id' => null]);

        $this->withToken($this->otherKey)
            ->getJson("/api/buddy/tasks/{$legacy->ulid}")
            ->assertOk();
    }

    public function test_admin_endpoint_issues_keys(): void
    {
        $response = $this->withToken($this->adminKey)
            ->postJson('/api/admin/clients', ['name' => 'new-agent']);

        $response->assertStatus(201)
            ->assertJsonPath('client_name', 'new-agent');

        $this->assertStringStartsWith('bdy_live_', $response->json('api_key'));
    }

    public function test_admin_endpoint_rejects_non_admin_keys(): void
    {
        $this->withToken($this->ownerKey)
            ->postJson('/api/admin/clients', ['name' => 'sneaky'])
            ->assertStatus(403);
    }

    public function test_admin_endpoint_never_mints_admin_keys(): void
    {
        $this->withToken($this->adminKey)
            ->postJson('/api/admin/clients', ['name' => 'escalation', 'scopes' => ['admin']])
            ->assertStatus(422);
    }
}
