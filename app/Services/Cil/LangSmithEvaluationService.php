<?php

namespace App\Services\Cil;

use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Projects the Controlled Improvement Loop onto LangSmith: evaluation
 * suites become datasets, evaluation runs become experiment sessions
 * linked to those datasets. Buddy's PostgreSQL rows remain the source
 * of truth (plan §4); LangSmith is the measurement and comparison
 * plane. Frozen suites sync exactly once — their dataset is immutable
 * after the first push, mirroring the §7.3 holdout guarantee.
 */
class LangSmithEvaluationService
{
    public function enabled(): bool
    {
        return (string) config('buddy.langsmith.api_key') !== '';
    }

    public function syncSuite(EvaluationSuite $suite): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($suite->langsmith_dataset_id !== null) {
            return $suite->langsmith_dataset_id;
        }

        try {
            $datasetId = $this->createDataset($suite);

            if ($datasetId === null) {
                return null;
            }

            $this->pushExamples($suite, $datasetId);

            $suite->update(['langsmith_dataset_id' => $datasetId]);

            return $datasetId;
        } catch (\Throwable $e) {
            Log::warning('LangSmith suite sync failed', [
                'suite' => $suite->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function startExperiment(EvaluationRun $run): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $datasetId = $run->suite?->langsmith_dataset_id;

        if ($datasetId === null) {
            return null;
        }

        try {
            // Candidate/run ids restart after migrate:fresh while LangSmith
            // session names live forever, so a bare id pair collides (409).
            $response = $this->client()->post('/sessions', [
                'name' => sprintf(
                    'buddy-cil-candidate-%d-run-%d-%s',
                    $run->improvement_candidate_id,
                    $run->id,
                    now()->format('ymdHis'),
                ),
                'reference_dataset_id' => $datasetId,
                'start_time' => now()->toISOString(),
                'description' => 'Buddy CIL evaluation run',
                'extra' => ['metadata' => ['service' => 'buddy', 'evaluation_run_id' => $run->id]],
            ]);

            if (! $response->successful()) {
                Log::warning('LangSmith experiment create failed', ['status' => $response->status()]);

                return null;
            }

            $experimentId = (string) $response->json('id');

            $run->update(['langsmith_experiment_id' => $experimentId]);

            return $experimentId;
        } catch (\Throwable $e) {
            Log::warning('LangSmith experiment create failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function endExperiment(EvaluationRun $run): void
    {
        if (! $this->enabled() || $run->langsmith_experiment_id === null) {
            return;
        }

        try {
            $this->client()->patch('/sessions/'.$run->langsmith_experiment_id, [
                'end_time' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LangSmith experiment end failed', ['error' => $e->getMessage()]);
        }
    }

    protected function createDataset(EvaluationSuite $suite): ?string
    {
        $name = 'buddy-suite-'.$suite->name;

        $existing = $this->client()->get('/datasets', ['name' => $name]);

        if ($existing->successful() && ($existing->json('0.id') ?? null) !== null) {
            return (string) $existing->json('0.id');
        }

        $response = $this->client()->post('/datasets', [
            'name' => $name,
            'description' => sprintf(
                'Buddy CIL %s suite "%s" (%s)',
                $suite->kind,
                $suite->name,
                $suite->frozen ? 'frozen' : 'mutable',
            ),
        ]);

        if (! $response->successful()) {
            Log::warning('LangSmith dataset create failed', ['status' => $response->status()]);

            return null;
        }

        return (string) $response->json('id');
    }

    protected function pushExamples(EvaluationSuite $suite, string $datasetId): void
    {
        $examples = [];

        foreach ($suite->cases as $index => $case) {
            $examples[] = [
                'dataset_id' => $datasetId,
                'inputs' => $case['inputs'] ?? $case,
                'outputs' => $case['expected'] ?? null,
                'metadata' => [
                    'suite' => $suite->name,
                    'case_index' => $index,
                ],
            ];
        }

        if ($examples === []) {
            return;
        }

        $response = $this->client()->post('/examples/bulk', $examples);

        if (! $response->successful()) {
            Log::warning('LangSmith example push failed', ['status' => $response->status()]);
        }
    }

    /**
     * @return array<int, string> example IDs keyed by case_index metadata
     */
    public function exampleIds(string $datasetId): array
    {
        try {
            $response = $this->client()->get('/examples', ['dataset' => $datasetId]);

            if (! $response->successful()) {
                return [];
            }

            $ids = [];

            foreach ($response->json() ?? [] as $example) {
                $index = $example['metadata']['case_index'] ?? null;

                if ($index !== null) {
                    $ids[(int) $index] = (string) $example['id'];
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            Log::warning('LangSmith example fetch failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     */
    public function postExperimentRuns(array $runs): void
    {
        if (! $this->enabled() || $runs === []) {
            return;
        }

        try {
            $response = $this->client()->post('/runs/batch', ['post' => $runs]);

            if (! $response->successful()) {
                Log::warning('LangSmith experiment run post rejected', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('LangSmith experiment run post failed', ['error' => $e->getMessage()]);
        }
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('buddy.langsmith.endpoint'))
            ->withHeaders(['x-api-key' => (string) config('buddy.langsmith.api_key')])
            ->timeout((int) config('buddy.langsmith.timeout', 2) * 5)
            ->connectTimeout(3)
            ->asJson();
    }
}
