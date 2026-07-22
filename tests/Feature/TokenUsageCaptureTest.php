<?php

namespace Tests\Feature;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\DTOs\EvaluationResult;
use App\DTOs\MemorySearchPage;
use App\Enums\Confidence;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Models\EvaluationSuite;
use App\Models\ImprovementCandidate;
use App\Services\Cil\CilReplayService;
use App\Services\Observability\LangSmithTracer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TokenUsageCaptureTest extends TestCase
{
    use RefreshDatabase;

    /*
     * A second fake() call replaces the queued responses instead of
     * appending, so multi-call tests must queue everything at once or
     * the overflow falls through to randomly generated schema output.
     */
    protected function fakeEvaluation(int $responses = 1): void
    {
        EvaluatorOptimizerAgent::fake(array_fill(0, $responses, [
            'accepted' => true,
            'confidence' => 'high',
            'summary' => 'ok',
            'recommended_plan' => [],
            'rejected_reasons' => [],
            'required_followups' => [],
            'risks' => [],
            'next_actions' => [],
            'memory_hits' => [],
        ]));
    }

    public function test_evaluation_stores_token_usage_on_the_run(): void
    {
        $this->fakeEvaluation();

        $task = BuddyTask::factory()->create(['problem_type' => 'bug']);

        $this->postJson("/api/buddy/tasks/{$task->ulid}/evaluate")->assertOk();

        $run = BuddyRun::query()->where('buddy_task_id', $task->id)->latest('id')->first();

        $this->assertIsArray($run->token_usage);
        $this->assertArrayHasKey('prompt_tokens', $run->token_usage);
        $this->assertArrayHasKey('completion_tokens', $run->token_usage);
    }

    public function test_tracer_emits_usage_metadata_and_persists_root_run_id(): void
    {
        config([
            'buddy.langsmith.tracing' => true,
            'buddy.langsmith.api_key' => 'ls-test-key',
            'buddy.langsmith.endpoint' => 'https://langsmith.test',
        ]);
        Http::fake(['langsmith.test/*' => Http::response([], 200)]);

        $task = BuddyTask::factory()->create();
        $run = BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => 1,
            'run_type' => 'evaluation',
            'status' => 'completed',
            'model_used' => 'gpt-5.4',
            'provider' => 'openai',
            'token_usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 340, 'reasoning_tokens' => 80],
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
        ]);

        (new LangSmithTracer)->traceEvaluation(
            $task,
            $run,
            new MemorySearchPage([], 'test'),
            new EvaluationResult(true, Confidence::High, 's', [], [], [], [], [], []),
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/runs/batch')) {
                return false;
            }

            $llm = collect($request['post'])->firstWhere('run_type', 'llm');

            return $llm !== null
                && $llm['outputs']['usage_metadata']['input_tokens'] === 1200
                && $llm['outputs']['usage_metadata']['output_tokens'] === 340
                && $llm['outputs']['usage_metadata']['total_tokens'] === 1540
                && $llm['extra']['metadata']['ls_model_name'] === 'gpt-5.4'
                && $llm['extra']['metadata']['ls_provider'] === 'openai';
        });

        $this->assertNotNull($run->refresh()->langsmith_run_id);
    }

    public function test_model_candidate_replays_both_pinned_legs(): void
    {
        $this->fakeEvaluation(2);

        $suite = EvaluationSuite::create([
            'name' => 'model-ab',
            'kind' => 'golden',
            'frozen' => false,
            'cases' => [
                ['inputs' => ['task_summary' => 'x', 'problem_type' => 'configuration'], 'expected' => ['accepted' => true]],
            ],
        ]);

        $candidate = ImprovementCandidate::create([
            'kind' => 'model',
            'rationale' => 'mini vs full',
            'payload' => ['model' => 'gpt-5.4-mini'],
        ]);

        $run = app(CilReplayService::class)->replay($candidate, $suite);

        $this->assertEquals(1.0, $run->baseline_metrics['accuracy']);
        $this->assertEquals(1.0, $run->candidate_metrics['accuracy']);
        $this->assertTrue($run->passed);
    }

    public function test_model_candidate_without_model_throws(): void
    {
        $suite = EvaluationSuite::create(['name' => 's', 'kind' => 'golden', 'frozen' => false, 'cases' => []]);
        $candidate = ImprovementCandidate::create(['kind' => 'model', 'rationale' => 'r', 'payload' => []]);

        $this->expectException(RuntimeException::class);

        app(CilReplayService::class)->replay($candidate, $suite);
    }
}
