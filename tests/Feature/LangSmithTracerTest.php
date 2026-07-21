<?php

namespace Tests\Feature;

use App\DTOs\EvaluationResult;
use App\DTOs\MemorySearchPage;
use App\DTOs\MemorySearchResult;
use App\Enums\Confidence;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Services\Observability\LangSmithTracer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LangSmithTracerTest extends TestCase
{
    use RefreshDatabase;

    protected LangSmithTracer $tracer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buddy.langsmith.tracing' => true,
            'buddy.langsmith.api_key' => 'ls-test-key',
            'buddy.langsmith.endpoint' => 'https://langsmith.test',
            'buddy.langsmith.project' => 'buddy-testing',
            'buddy.langsmith.send_prompts' => false,
        ]);

        $this->tracer = new LangSmithTracer;
    }

    protected function makeRun(BuddyTask $task): BuddyRun
    {
        return BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => 1,
            'run_type' => 'evaluation',
            'status' => 'completed',
            'model_used' => 'gpt-5.4',
            'provider' => 'openai',
            'prompt_hash' => str_repeat('a', 64),
            'prompt_modules' => ['core/identity'],
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
        ]);
    }

    protected function makeResult(): EvaluationResult
    {
        return new EvaluationResult(
            accepted: true,
            confidence: Confidence::High,
            summary: 'sensitive model output summary',
            recommendedPlan: [],
            rejectedReasons: [],
            requiredFollowups: [],
            risks: [],
            nextActions: [],
            memoryHits: ['hit one'],
        );
    }

    protected function makePage(): MemorySearchPage
    {
        return new MemorySearchPage(
            results: [new MemorySearchResult(pointId: 'mem-1', score: 0.91, summary: 'past episode')],
            backend: 'hub',
        );
    }

    public function test_it_posts_a_complete_run_tree_to_the_batch_endpoint(): void
    {
        Http::fake(['langsmith.test/runs/batch' => Http::response(['message' => 'ok'], 202)]);

        $task = BuddyTask::factory()->create();
        $this->tracer->traceEvaluation($task, $this->makeRun($task), $this->makePage(), $this->makeResult());

        Http::assertSent(function ($request) use ($task) {
            $runs = $request['post'];

            return $request->url() === 'https://langsmith.test/runs/batch'
                && $request->hasHeader('x-api-key', 'ls-test-key')
                && count($runs) === 3
                && $runs[0]['run_type'] === 'chain'
                && $runs[0]['session_name'] === 'buddy-testing'
                && $runs[0]['inputs']['task_ulid'] === $task->ulid
                && $runs[1]['run_type'] === 'retriever'
                && $runs[1]['parent_run_id'] === $runs[0]['id']
                && $runs[1]['outputs']['memories'][0]['memory_id'] === 'mem-1'
                && $runs[2]['run_type'] === 'llm'
                && $runs[2]['trace_id'] === $runs[0]['id'];
        });
    }

    public function test_it_redacts_content_by_default(): void
    {
        Http::fake(['langsmith.test/*' => Http::response([], 202)]);

        $task = BuddyTask::factory()->create(['task_summary' => 'secret business context']);
        $this->tracer->traceEvaluation($task, $this->makeRun($task), $this->makePage(), $this->makeResult());

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return ! str_contains($body, 'secret business context')
                && ! str_contains($body, 'sensitive model output summary');
        });
    }

    public function test_it_sends_content_when_send_prompts_is_enabled(): void
    {
        config(['buddy.langsmith.send_prompts' => true]);
        Http::fake(['langsmith.test/*' => Http::response([], 202)]);

        $task = BuddyTask::factory()->create(['task_summary' => 'secret business context']);
        $this->tracer->traceEvaluation($task, $this->makeRun($task), $this->makePage(), $this->makeResult());

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return str_contains($body, 'secret business context')
                && str_contains($body, 'sensitive model output summary');
        });
    }

    public function test_it_sends_nothing_when_disabled(): void
    {
        config(['buddy.langsmith.tracing' => false]);
        Http::fake();

        $task = BuddyTask::factory()->create();
        $this->tracer->traceEvaluation($task, $this->makeRun($task), $this->makePage(), $this->makeResult());

        Http::assertNothingSent();
    }

    public function test_it_sends_nothing_without_an_api_key(): void
    {
        config(['buddy.langsmith.api_key' => '']);
        Http::fake();

        $task = BuddyTask::factory()->create();
        $this->tracer->traceEvaluation($task, $this->makeRun($task), $this->makePage(), $this->makeResult());

        Http::assertNothingSent();
    }

    public function test_api_failure_never_throws(): void
    {
        Http::fake(['langsmith.test/*' => Http::response(['error' => 'boom'], 500)]);

        $task = BuddyTask::factory()->create();

        $this->tracer->traceEvaluation($task, $this->makeRun($task), $this->makePage(), $this->makeResult());

        $this->assertTrue(true);
    }

    public function test_failed_runs_carry_the_error(): void
    {
        Http::fake(['langsmith.test/*' => Http::response([], 202)]);

        $task = BuddyTask::factory()->create();

        $this->tracer->traceEvaluation(
            $task,
            $this->makeRun($task),
            MemorySearchPage::degraded('hub', 'unreachable'),
            null,
            new \RuntimeException('provider exploded'),
        );

        Http::assertSent(function ($request) {
            $runs = $request['post'];

            return $runs[0]['error'] === 'provider exploded'
                && $runs[1]['outputs']['degraded'] === true;
        });
    }
}
