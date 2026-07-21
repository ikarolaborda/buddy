<?php

namespace Tests\Feature;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Ai\Prompting\PromptCompiler;
use App\Enums\ProblemType;
use App\Models\BuddyTask;
use App\Models\EvaluationSuite;
use App\Models\ImprovementCandidate;
use App\Models\PromotionDecision;
use App\Services\Cil\CilReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CilReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function agentResponse(bool $accepted): array
    {
        return [
            'accepted' => $accepted,
            'confidence' => 'high',
            'summary' => 's',
            'recommended_plan' => [],
            'rejected_reasons' => [],
            'required_followups' => [],
            'risks' => [],
            'next_actions' => [],
            'memory_hits' => [],
        ];
    }

    protected function makeSuite(array $attributes = []): EvaluationSuite
    {
        return EvaluationSuite::create(array_merge([
            'name' => 'golden',
            'kind' => 'frozen',
            'frozen' => true,
            'cases' => [
                ['inputs' => ['task_summary' => 'a bug', 'problem_type' => 'bug'], 'expected' => ['accepted' => true]],
                ['inputs' => ['task_summary' => 'vague ask', 'problem_type' => 'ambiguous'], 'expected' => ['accepted' => false]],
            ],
        ], $attributes));
    }

    protected function makeCandidate(): ImprovementCandidate
    {
        return ImprovementCandidate::create([
            'kind' => 'prompt',
            'rationale' => 'stricter acceptance',
            'payload' => ['modules' => ['core/decision-policy' => '# Stricter policy']],
        ]);
    }

    public function test_prompt_compiler_applies_overrides(): void
    {
        $task = new BuddyTask;
        $task->problem_type = ProblemType::Bug;

        $compiler = app(PromptCompiler::class);
        $baseline = $compiler->compile('evaluator-optimizer', $task);
        $overridden = $compiler->compile('evaluator-optimizer', $task, [
            'core/decision-policy' => '# Stricter policy',
        ]);

        $this->assertStringContainsString('# Stricter policy', $overridden->text);
        $this->assertNotSame($baseline->contentHash, $overridden->contentHash);
        $this->assertSame($baseline->moduleIds, $overridden->moduleIds);
    }

    public function test_replay_records_both_variants_and_projects_the_experiment(): void
    {
        config(['buddy.langsmith.api_key' => 'k', 'buddy.langsmith.endpoint' => 'https://langsmith.test']);
        Http::fake([
            'langsmith.test/sessions*' => Http::response(['id' => 'exp-1']),
            'langsmith.test/examples?dataset=ds-1' => Http::response([
                ['id' => 'ex-0', 'metadata' => ['case_index' => 0]],
                ['id' => 'ex-1', 'metadata' => ['case_index' => 1]],
            ]),
            'langsmith.test/runs/batch' => Http::response(['message' => 'ok'], 202),
        ]);

        // Baseline gets case 1 wrong (accepts the vague ask); candidate gets both right.
        EvaluatorOptimizerAgent::fake([
            $this->agentResponse(true),
            $this->agentResponse(true),
            $this->agentResponse(true),
            $this->agentResponse(false),
        ]);

        $suite = $this->makeSuite(['langsmith_dataset_id' => 'ds-1']);
        $run = app(CilReplayService::class)->replay($this->makeCandidate(), $suite);

        $this->assertEquals(0.5, $run->baseline_metrics['accuracy']);
        $this->assertEquals(1.0, $run->candidate_metrics['accuracy']);
        $this->assertTrue($run->passed);
        $this->assertNotNull($run->completed_at);
        $this->assertSame('exp-1', $run->langsmith_experiment_id);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/runs/batch')) {
                return false;
            }

            $runs = $request['post'];

            return count($runs) === 4
                && $runs[0]['session_id'] === 'exp-1'
                && $runs[0]['reference_example_id'] === 'ex-0'
                && str_contains($runs[0]['name'], 'baseline-case-0')
                && str_contains($runs[3]['name'], 'candidate-case-1');
        });
    }

    public function test_replay_enforces_the_evaluation_budget(): void
    {
        config(['buddy.cil.max_evaluations_per_cycle' => 3]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cycle budget');

        app(CilReplayService::class)->replay($this->makeCandidate(), $this->makeSuite());
    }

    public function test_replay_rejects_non_prompt_candidates(): void
    {
        $candidate = ImprovementCandidate::create(['kind' => 'routing', 'rationale' => 'x', 'payload' => []]);

        $this->expectException(\RuntimeException::class);

        app(CilReplayService::class)->replay($candidate, $this->makeSuite());
    }

    public function test_decide_requires_a_completed_run_and_records_the_decision(): void
    {
        $candidate = $this->makeCandidate();

        $this->artisan('buddy:cil-decide', [
            'candidate' => $candidate->id, '--approve' => true, '--by' => 'ikaro',
        ])->assertFailed();

        $suite = $this->makeSuite();
        $candidate->evaluationRuns()->create([
            'evaluation_suite_id' => $suite->id,
            'baseline_metrics' => ['accuracy' => 0.5],
            'candidate_metrics' => ['accuracy' => 1.0],
            'passed' => true,
            'completed_at' => now(),
        ]);

        $this->artisan('buddy:cil-decide', [
            'candidate' => $candidate->id, '--approve' => true, '--by' => 'ikaro', '--rationale' => 'better',
        ])->assertSuccessful();

        $this->assertSame('approved', $candidate->fresh()->status);
        $this->assertDatabaseHas('promotion_decisions', [
            'improvement_candidate_id' => $candidate->id,
            'decided_by' => 'ikaro',
            'approved' => true,
        ]);
    }

    public function test_decide_rejects_ambiguous_flags(): void
    {
        $candidate = $this->makeCandidate();

        $this->artisan('buddy:cil-decide', [
            'candidate' => $candidate->id, '--approve' => true, '--reject' => true, '--by' => 'ikaro',
        ])->assertFailed();

        $this->assertSame(0, PromotionDecision::count());
    }
}
