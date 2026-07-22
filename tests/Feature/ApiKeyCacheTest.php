<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiKeyCacheTest extends TestCase
{
    use RefreshDatabase;

    protected ApiKeyService $service;

    protected ApiClient $client;

    protected string $plaintext;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buddy.api.key_cache_ttl' => 60]);

        $this->service = app(ApiKeyService::class);
        $this->client = ApiClient::create(['name' => 'cache-test', 'project' => 'buddy']);
        $this->plaintext = $this->service
            ->issue($this->client, [ApiScope::TasksRead])['plaintext'];
    }

    protected function apiKeySelectCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'api_keys') && str_starts_with(trim($q['query']), 'select'));

        DB::disableQueryLog();

        return $queries->count();
    }

    public function test_second_verify_skips_the_database(): void
    {
        $this->service->verify($this->plaintext);

        $count = $this->apiKeySelectCount(fn () => $this->service->verify($this->plaintext));

        $this->assertSame(0, $count);
    }

    public function test_ttl_zero_disables_caching(): void
    {
        config(['buddy.api.key_cache_ttl' => 0]);

        $this->service->verify($this->plaintext);

        $count = $this->apiKeySelectCount(fn () => $this->service->verify($this->plaintext));

        $this->assertSame(1, $count);
    }

    public function test_revoke_invalidates_the_cache_immediately(): void
    {
        $key = $this->service->verify($this->plaintext);
        $this->assertNotNull($key);

        $this->service->revoke($key);

        $this->assertNull($this->service->verify($this->plaintext));
    }

    public function test_expiry_is_enforced_against_a_cached_key(): void
    {
        $expiring = $this->service->issue(
            $this->client,
            [ApiScope::TasksRead],
            now()->addSeconds(5),
        )['plaintext'];

        $this->assertNotNull($this->service->verify($expiring));

        $this->travel(10)->seconds();

        $this->assertNull($this->service->verify($expiring));
    }

    public function test_last_used_writes_are_throttled(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->verify($this->plaintext);
        $this->service->verify($this->plaintext);
        $this->service->verify($this->plaintext);

        $updates = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_starts_with(trim($q['query']), 'update') && str_contains($q['query'], 'api_keys'));

        DB::disableQueryLog();

        $this->assertSame(1, $updates->count());
    }

    public function test_wrong_secret_is_rejected_even_when_key_is_cached(): void
    {
        $this->assertNotNull($this->service->verify($this->plaintext));

        $tampered = substr($this->plaintext, 0, -4).'aaaa';

        $this->assertNull($this->service->verify($tampered));
    }
}
