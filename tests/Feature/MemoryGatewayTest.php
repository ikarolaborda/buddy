<?php

namespace Tests\Feature;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryCandidate;
use App\DTOs\MemoryQuery;
use App\DTOs\MemorySearchPage;
use App\Services\Memory\LegacyQdrantMemoryGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class MemoryGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_binds_the_legacy_gateway_by_default(): void
    {
        $this->assertInstanceOf(LegacyQdrantMemoryGateway::class, app(MemoryGateway::class));
    }

    public function test_search_returns_typed_degraded_state_when_qdrant_is_unreachable(): void
    {
        Embeddings::fake();

        $page = app(MemoryGateway::class)->search(new MemoryQuery('similar oauth failures'));

        $this->assertInstanceOf(MemorySearchPage::class, $page);
        $this->assertTrue($page->degraded);
        $this->assertNotNull($page->degradedReason);
        $this->assertSame([], $page->results);
        $this->assertSame('legacy', $page->backend);
    }

    public function test_store_degrades_to_null_instead_of_throwing(): void
    {
        Embeddings::fake();

        $receipt = app(MemoryGateway::class)->store(new MemoryCandidate(
            summary: 'test learning',
            tags: ['test'],
        ));

        $this->assertNull($receipt);
    }

    public function test_degraded_page_factory_carries_reason(): void
    {
        $page = MemorySearchPage::degraded('hub', 'connection refused');

        $this->assertTrue($page->degraded);
        $this->assertSame('hub', $page->backend);
        $this->assertSame('connection refused', $page->degradedReason);
    }
}
