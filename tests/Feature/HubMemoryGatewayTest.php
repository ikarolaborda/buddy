<?php

namespace Tests\Feature;

use App\DTOs\MemoryCandidate;
use App\DTOs\MemoryFeedback;
use App\DTOs\MemoryQuery;
use App\Services\Memory\HubMemoryGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubMemoryGatewayTest extends TestCase
{
    protected HubMemoryGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config(['buddy.memory.hub.base_url' => 'http://hub.test']);

        $this->gateway = new HubMemoryGateway;
    }

    public function test_search_posts_hub_contract_and_maps_episode_results(): void
    {
        Http::fake([
            'hub.test/api/search' => Http::response([
                'results' => [
                    [
                        'id' => 'mem-1',
                        'score' => 0.91,
                        'episode' => [
                            'problem' => 'past problem',
                            'summary' => 'past problem summary',
                            'tags' => ['laravel'],
                        ],
                    ],
                ],
            ]),
        ]);

        $page = $this->gateway->search(new MemoryQuery('similar failures', limit: 3));

        Http::assertSent(function ($request) {
            return $request->url() === 'http://hub.test/api/search'
                && $request['query'] === 'similar failures'
                && $request['limit'] === 3;
        });

        $this->assertFalse($page->degraded);
        $this->assertSame('hub', $page->backend);
        $this->assertCount(1, $page->results);
        $this->assertSame('mem-1', $page->results[0]->pointId);
        $this->assertSame('past problem summary', $page->results[0]->summary);
        $this->assertSame(['laravel'], $page->results[0]->tags);
    }

    public function test_store_sends_problem_solution_impact(): void
    {
        Http::fake([
            'hub.test/api/store' => Http::response(['id' => 'mem-9', 'status' => 'stored']),
        ]);

        $receipt = $this->gateway->store(new MemoryCandidate(
            summary: 'learning summary',
            tags: ['buddy_learning'],
            problem: 'the task summary',
            solution: 'the learning',
            impact: 'Outcome: accepted (confidence: high)',
            fileReferences: ['app/Foo.php'],
        ));

        Http::assertSent(function ($request) {
            return $request->url() === 'http://hub.test/api/store'
                && $request['problem'] === 'the task summary'
                && $request['solution'] === 'the learning'
                && $request['impact'] === 'Outcome: accepted (confidence: high)'
                && $request['file_references'] === ['app/Foo.php'];
        });

        $this->assertNotNull($receipt);
        $this->assertSame('mem-9', $receipt->memoryId);
        $this->assertSame('hub', $receipt->backend);
    }

    public function test_store_falls_back_to_summary_when_structured_fields_missing(): void
    {
        Http::fake([
            'hub.test/api/store' => Http::response(['id' => 'mem-10']),
        ]);

        $this->gateway->store(new MemoryCandidate(summary: 'only a summary'));

        Http::assertSent(function ($request) {
            return $request['problem'] === 'only a summary'
                && $request['solution'] === 'only a summary';
        });
    }

    public function test_feedback_posts_kind_to_per_memory_path(): void
    {
        Http::fake([
            'hub.test/api/memories/mem-3/feedback' => Http::response(['status' => 'ok']),
        ]);

        $this->gateway->feedback(new MemoryFeedback(memoryId: 'mem-3', useful: false));

        Http::assertSent(function ($request) {
            return $request->url() === 'http://hub.test/api/memories/mem-3/feedback'
                && $request['kind'] === 'not_useful';
        });
    }

    public function test_search_degrades_on_http_error(): void
    {
        Http::fake([
            'hub.test/api/search' => Http::response(['error' => 'boom'], 500),
        ]);

        $page = $this->gateway->search(new MemoryQuery('anything'));

        $this->assertTrue($page->degraded);
        $this->assertStringStartsWith('hub search returned 500', $page->degradedReason);
    }

    public function test_health_hits_healthz(): void
    {
        Http::fake([
            'hub.test/api/healthz' => Http::response(['ok' => true]),
        ]);

        $health = $this->gateway->health();

        $this->assertTrue($health->healthy);
        $this->assertSame('hub', $health->backend);
    }
}
