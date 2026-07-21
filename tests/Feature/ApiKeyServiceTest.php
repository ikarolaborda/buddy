<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ApiKeyService $service;

    protected ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ApiKeyService::class);
        $this->client = ApiClient::create(['name' => 'svc-test', 'project' => 'buddy']);
    }

    public function test_it_issues_keys_in_the_documented_format(): void
    {
        $issued = $this->service->issue($this->client, [ApiScope::TasksWrite]);

        $this->assertMatchesRegularExpression(
            '/^bdy_live_[a-z0-9]{16}_[a-f0-9]{64}$/',
            $issued['plaintext'],
        );

        $this->assertStringNotContainsString(
            explode('_', $issued['plaintext'])[3],
            $issued['key']->secret_digest,
        );
    }

    public function test_it_verifies_a_valid_key(): void
    {
        $issued = $this->service->issue($this->client, [ApiScope::TasksWrite]);

        $verified = $this->service->verify($issued['plaintext']);

        $this->assertNotNull($verified);
        $this->assertSame($issued['key']->id, $verified->id);
        $this->assertNotNull($verified->last_used_at);
    }

    public function test_it_rejects_a_tampered_secret(): void
    {
        $issued = $this->service->issue($this->client, [ApiScope::TasksWrite]);

        $tampered = substr($issued['plaintext'], 0, -4).'0000';

        $this->assertNull($this->service->verify($tampered));
    }

    public function test_it_rejects_unknown_public_ids(): void
    {
        $this->assertNull($this->service->verify('bdy_live_aaaaaaaaaaaaaaaa_'.str_repeat('ab', 32)));
    }

    public function test_it_rejects_keys_for_inactive_clients(): void
    {
        $issued = $this->service->issue($this->client, [ApiScope::TasksWrite]);

        $this->client->update(['active' => false]);

        $this->assertNull($this->service->verify($issued['plaintext']));
    }

    public function test_admin_scope_grants_all_scopes(): void
    {
        $issued = $this->service->issue($this->client, [ApiScope::Admin]);

        $this->assertTrue($issued['key']->hasScope(ApiScope::TasksWrite));
        $this->assertTrue($issued['key']->hasScope(ApiScope::MemoryRead));
    }
}
