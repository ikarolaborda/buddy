<?php

namespace Tests\Feature;

use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\ImprovementCandidate;
use App\Services\Cil\LangSmithEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LangSmithEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LangSmithEvaluationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buddy.langsmith.api_key' => 'ls-test-key',
            'buddy.langsmith.endpoint' => 'https://langsmith.test',
        ]);

        $this->service = new LangSmithEvaluationService;
    }

    protected function makeSuite(array $attributes = []): EvaluationSuite
    {
        return EvaluationSuite::create(array_merge([
            'name' => 'golden-bugs',
            'kind' => 'frozen',
            'frozen' => true,
            'cases' => [
                ['inputs' => ['task_summary' => 'case one'], 'expected' => ['accepted' => true]],
                ['inputs' => ['task_summary' => 'case two'], 'expected' => ['accepted' => false]],
            ],
        ], $attributes));
    }

    public function test_it_creates_a_dataset_and_pushes_examples(): void
    {
        Http::fake([
            'langsmith.test/datasets?name=buddy-suite-golden-bugs' => Http::response([]),
            'langsmith.test/datasets' => Http::response(['id' => 'ds-123']),
            'langsmith.test/examples/bulk' => Http::response(['items' => []]),
        ]);

        $suite = $this->makeSuite();
        $datasetId = $this->service->syncSuite($suite);

        $this->assertSame('ds-123', $datasetId);
        $this->assertSame('ds-123', $suite->fresh()->langsmith_dataset_id);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/examples/bulk')) {
                return false;
            }

            $examples = $request->data();

            return count($examples) === 2
                && $examples[0]['dataset_id'] === 'ds-123'
                && $examples[0]['inputs'] === ['task_summary' => 'case one']
                && $examples[0]['outputs'] === ['accepted' => true]
                && $examples[1]['metadata']['case_index'] === 1;
        });
    }

    public function test_it_never_resyncs_a_synced_suite(): void
    {
        Http::fake();

        $suite = $this->makeSuite(['langsmith_dataset_id' => 'ds-existing']);

        $this->assertSame('ds-existing', $this->service->syncSuite($suite));

        Http::assertNothingSent();
    }

    public function test_it_reuses_an_existing_dataset_by_name(): void
    {
        Http::fake([
            'langsmith.test/datasets?name=buddy-suite-golden-bugs' => Http::response([['id' => 'ds-found']]),
            'langsmith.test/examples/bulk' => Http::response(['items' => []]),
        ]);

        $suite = $this->makeSuite();

        $this->assertSame('ds-found', $this->service->syncSuite($suite));
    }

    public function test_it_records_experiments_as_sessions(): void
    {
        Http::fake([
            'langsmith.test/sessions' => Http::response(['id' => 'exp-9']),
        ]);

        $suite = $this->makeSuite(['langsmith_dataset_id' => 'ds-123']);
        $candidate = ImprovementCandidate::create([
            'kind' => 'prompt',
            'rationale' => 'test',
            'payload' => [],
        ]);
        $run = EvaluationRun::create([
            'improvement_candidate_id' => $candidate->id,
            'evaluation_suite_id' => $suite->id,
        ]);

        $experimentId = $this->service->startExperiment($run);

        $this->assertSame('exp-9', $experimentId);
        $this->assertSame('exp-9', $run->fresh()->langsmith_experiment_id);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sessions')
                && $request['reference_dataset_id'] === 'ds-123'
                && str_contains($request['name'], 'buddy-cil-candidate-');
        });
    }

    public function test_experiment_requires_a_synced_suite(): void
    {
        Http::fake();

        $suite = $this->makeSuite();
        $candidate = ImprovementCandidate::create(['kind' => 'prompt', 'rationale' => 'x', 'payload' => []]);
        $run = EvaluationRun::create([
            'improvement_candidate_id' => $candidate->id,
            'evaluation_suite_id' => $suite->id,
        ]);

        $this->assertNull($this->service->startExperiment($run));

        Http::assertNothingSent();
    }

    public function test_end_experiment_patches_the_session(): void
    {
        Http::fake([
            'langsmith.test/sessions/exp-9' => Http::response(['id' => 'exp-9']),
        ]);

        $suite = $this->makeSuite(['langsmith_dataset_id' => 'ds-123']);
        $candidate = ImprovementCandidate::create(['kind' => 'prompt', 'rationale' => 'x', 'payload' => []]);
        $run = EvaluationRun::create([
            'improvement_candidate_id' => $candidate->id,
            'evaluation_suite_id' => $suite->id,
            'langsmith_experiment_id' => 'exp-9',
        ]);

        $this->service->endExperiment($run);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/sessions/exp-9'));
    }

    public function test_it_is_inert_without_an_api_key(): void
    {
        config(['buddy.langsmith.api_key' => '']);
        Http::fake();

        $suite = $this->makeSuite();

        $this->assertNull($this->service->syncSuite($suite));
        Http::assertNothingSent();
    }

    public function test_api_failure_returns_null_without_throwing(): void
    {
        Http::fake([
            'langsmith.test/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $suite = $this->makeSuite();

        $this->assertNull($this->service->syncSuite($suite));
        $this->assertNull($suite->fresh()->langsmith_dataset_id);
    }
}
