<?php

namespace Tests\Feature;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Ai\Prompting\ContextEnvelope;
use App\Contracts\EcosystemKnowledgeGateway;
use App\Contracts\MemoryGateway;
use App\DTOs\EcosystemKnowledgeHit;
use App\DTOs\EcosystemKnowledgePage;
use App\DTOs\EcosystemKnowledgeQuery;
use App\DTOs\MemorySearchPage;
use App\DTOs\MemorySearchResult;
use App\DTOs\ProblemPacket;
use App\Enums\ProblemType;
use App\Jobs\PrefetchEcosystemKnowledgeJob;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use App\Services\Knowledge\AlgoliaEcosystemKnowledgeGateway;
use App\Services\Knowledge\EcosystemKnowledgeQueryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\HasTools;
use Tests\TestCase;

class EcosystemKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_is_sanitized_and_scoped_to_product_and_ecosystem_indices(): void
    {
        config()->set('buddy.knowledge.environment', 'test');
        $task = BuddyTask::factory()->make([
            'repo' => 'aerolambda/theravista-api',
            'task_summary' => 'Fix token=raw-secret for person@example.com',
        ]);

        $query = app(EcosystemKnowledgeQueryFactory::class)->forTask($task);

        $this->assertSame('theravista', $query->product);
        $this->assertSame([
            'test_internal_theravista_knowledge',
            'test_internal_aerolambda_knowledge',
        ], $query->indices);
        $this->assertStringStartsWith('Fix token=[redacted]', $query->query);
        $this->assertStringNotContainsString('raw-secret', $query->query);
        $this->assertStringNotContainsString('person@example.com', $query->query);
    }

    public function test_algolia_gateway_uses_one_analytics_free_multi_index_request(): void
    {
        config()->set('buddy.knowledge.algolia.application_id', 'application');
        config()->set('buddy.knowledge.algolia.search_key', 'restricted-search-key');
        Http::fake([
            'https://application-dsn.algolia.net/*' => Http::response([
                'results' => [
                    ['hits' => [[
                        'objectID' => 'theravista-record',
                        'canonical_id' => 'shared-contract',
                        'title' => 'Theravista contract',
                        'body' => 'Current API contract',
                        'product' => 'theravista',
                        'repository' => 'theravista-api',
                        'content_type' => 'api_contract',
                        'source_path' => 'openapi/openapi.yaml',
                        'source_revision' => 'abc123',
                        'tags' => ['openapi'],
                    ]]],
                    ['hits' => [[
                        'objectID' => 'ecosystem-duplicate',
                        'canonical_id' => 'shared-contract',
                        'title' => 'Older ecosystem copy',
                        'body' => 'Duplicate',
                    ]]],
                ],
            ]),
        ]);

        $page = app(AlgoliaEcosystemKnowledgeGateway::class)->search(new EcosystemKnowledgeQuery(
            query: 'API contract',
            indices: ['test_internal_theravista_knowledge', 'test_internal_aerolambda_knowledge'],
            limit: 6,
            product: 'theravista',
        ));

        $this->assertFalse($page->degraded);
        $this->assertCount(1, $page->results);
        $this->assertSame('theravista-record', $page->results[0]->recordId);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            parse_str($payload['requests'][0]['params'], $params);

            return $request->hasHeader('X-Algolia-API-Key', 'restricted-search-key')
                && count($payload['requests']) === 2
                && $params['analytics'] === 'false'
                && $params['filters'] === 'visibility:internal AND status:current';
        });
    }

    public function test_background_prefetch_persists_a_snapshot_without_throwing_into_evaluation(): void
    {
        config()->set('buddy.knowledge.prefetch_enabled', true);
        $task = BuddyTask::factory()->create(['repo' => 'aerolambda/theravista-api']);
        $gateway = new class implements EcosystemKnowledgeGateway
        {
            public function search(EcosystemKnowledgeQuery $query): EcosystemKnowledgePage
            {
                return new EcosystemKnowledgePage([
                    new EcosystemKnowledgeHit(
                        recordId: 'record-1',
                        index: $query->indices[0],
                        score: 1.0,
                        title: 'Contract',
                        snippet: 'Exact current behavior',
                        product: 'theravista',
                        repository: 'theravista-api',
                        contentType: 'api_contract',
                        sourcePath: 'openapi/openapi.yaml',
                        sourceRevision: 'abc123',
                    ),
                ]);
            }
        };

        (new PrefetchEcosystemKnowledgeJob($task))->handle(
            $gateway,
            app(EcosystemKnowledgeQueryFactory::class),
        );

        $task->refresh();
        $this->assertSame('ready', $task->knowledge_context_status);
        $this->assertSame('record-1', $task->knowledge_context[0]['record_id']);
        $this->assertNotNull($task->knowledge_context_hash);
    }

    public function test_task_creation_uses_the_outbox_to_dispatch_prefetch_after_commit(): void
    {
        Queue::fake();
        config()->set('buddy.knowledge.prefetch_enabled', true);

        $task = app(EvaluatorOptimizerService::class)->createTask(new ProblemPacket(
            sourceAgent: 'test-agent',
            taskSummary: 'Confirm the current API contract',
            problemType: ProblemType::Integration,
            repo: 'aerolambda/theravista-api',
        ));

        $this->assertSame('pending', $task->knowledge_context_status);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'buddy.knowledge.prefetch.requested',
            'message_key' => $task->ulid,
        ]);
        Queue::assertPushed(PrefetchEcosystemKnowledgeJob::class, fn (PrefetchEcosystemKnowledgeJob $job): bool => $job->uniqueId() === $task->ulid);
    }

    public function test_grounding_is_bounded_and_evaluator_has_no_second_memory_search_tool(): void
    {
        config()->set('buddy.knowledge.context_enabled', true);
        config()->set('buddy.knowledge.max_context_chars', 1200);
        $task = BuddyTask::factory()->create([
            'knowledge_context_status' => 'ready',
            'knowledge_context' => [[
                'record_id' => 'algolia-record-1',
                'index' => 'test_internal_buddy_knowledge',
                'source_path' => 'docs/contract.md',
                'source_revision' => 'abc123',
                'title' => 'Contract',
                'snippet' => str_repeat('fresh ', 500),
            ]],
        ]);
        $memory = new MemorySearchPage([
            new MemorySearchResult('memory-1', 0.91, str_repeat('episode ', 500)),
        ], 'qdrant');

        $prompt = app(ContextEnvelope::class)->forTask($task, 'Packet', 'Evaluate.', $memory);

        $this->assertStringContainsString('algolia-record-1', $prompt);
        $this->assertStringContainsString('memory-1', $prompt);
        $this->assertLessThan(5000, strlen($prompt));
        $this->assertFalse(is_a(EvaluatorOptimizerAgent::class, HasTools::class, true));
    }

    public function test_recommendation_provenance_accepts_only_citations_from_the_supplied_snapshot(): void
    {
        config()->set('buddy.knowledge.context_enabled', true);
        $this->mock(MemoryGateway::class)
            ->shouldReceive('search')
            ->once()
            ->andReturn(new MemorySearchPage([], 'qdrant'));
        EvaluatorOptimizerAgent::fake([[
            'accepted' => true,
            'confidence' => 'high',
            'summary' => 'Use the current contract.',
            'recommended_plan' => ['Follow the contract'],
            'rejected_reasons' => [],
            'required_followups' => [],
            'risks' => [],
            'next_actions' => ['Implement'],
            'memory_hits' => [],
            'knowledge_hits' => ['record-1', 'invented-record'],
        ]]);
        $task = BuddyTask::factory()->create([
            'knowledge_context_status' => 'ready',
            'knowledge_context_hash' => hash('sha256', 'snapshot'),
            'knowledge_context' => [[
                'record_id' => 'record-1',
                'index' => 'test_internal_buddy_knowledge',
                'source_path' => 'docs/contract.md',
                'source_revision' => 'abc123',
                'source_url' => 'https://github.com/ikarolaborda/buddy/blob/abc123/docs/contract.md',
                'snippet' => 'Current contract',
                'snippet_hash' => hash('sha256', 'Current contract'),
            ]],
        ]);

        $result = app(EvaluatorOptimizerService::class)->evaluate($task);

        $this->assertSame(['record-1'], array_column($result->knowledgeHits, 'record_id'));
        $this->assertSame(
            ['record-1'],
            array_column($task->latestRecommendation()->knowledge_hits, 'record_id'),
        );
    }

    public function test_index_command_supports_a_credential_free_dry_run(): void
    {
        $directory = sys_get_temp_dir().'/buddy-index-command-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);
        File::put($directory.'/README.md', "# Contract\nCurrent behavior.");

        try {
            $this->artisan('buddy:index-ecosystem-knowledge', [
                'product' => 'buddy',
                'sources' => ["buddy={$directory}"],
                '--revision' => 'abc123',
                '--environment' => 'test',
                '--dry-run' => true,
            ])->assertSuccessful()->expectsOutputToContain('Dry run: extracted 1 record(s)');
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
