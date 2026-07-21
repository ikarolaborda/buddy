<?php

namespace App\Services\Cil;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Ai\Prompting\PromptCompiler;
use App\Enums\ProblemType;
use App\Models\BuddyTask;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\ImprovementCandidate;
use Illuminate\Support\Str;
use RuntimeException;

/*
 * The CIL replay engine (plan §7 loop steps 4-6): replays baseline and
 * candidate prompt variants against a suite's cases, records both metric
 * sets on the evaluation run, and projects every case invocation into
 * the LangSmith experiment with its reference example. Replays bypass
 * the task state machine entirely — synthetic tasks are never persisted
 * and never touch queues, leases, or the outbox. Promotion remains a
 * human decision; `passed` is a report-only signal.
 */
class CilReplayService
{
    public function __construct(
        protected PromptCompiler $compiler,
        protected LangSmithEvaluationService $langsmith,
    ) {}

    public function replay(ImprovementCandidate $candidate, EvaluationSuite $suite): EvaluationRun
    {
        if ($candidate->kind !== 'prompt') {
            throw new RuntimeException("Only prompt candidates can be replayed; got '{$candidate->kind}'.");
        }

        $overrides = $candidate->payload['modules'] ?? [];

        if ($overrides === []) {
            throw new RuntimeException('Candidate payload has no module overrides.');
        }

        $budget = count($suite->cases) * 2;
        $maxEvaluations = (int) config('buddy.cil.max_evaluations_per_cycle');

        if ($budget > $maxEvaluations) {
            throw new RuntimeException(
                "Replay needs {$budget} evaluations but the cycle budget is {$maxEvaluations}.",
            );
        }

        $this->langsmith->syncSuite($suite);

        $run = EvaluationRun::create([
            'improvement_candidate_id' => $candidate->id,
            'evaluation_suite_id' => $suite->id,
        ]);

        $experimentId = $this->langsmith->startExperiment($run);
        $exampleIds = $suite->langsmith_dataset_id !== null
            ? $this->langsmith->exampleIds($suite->langsmith_dataset_id)
            : [];

        $experimentRuns = [];

        $baseline = $this->replayVariant($suite, [], 'baseline', $experimentId, $exampleIds, $experimentRuns);
        $candidateMetrics = $this->replayVariant($suite, $overrides, 'candidate', $experimentId, $exampleIds, $experimentRuns);

        $this->langsmith->postExperimentRuns($experimentRuns);
        $this->langsmith->endExperiment($run->refresh());

        $run->update([
            'baseline_metrics' => $baseline,
            'candidate_metrics' => $candidateMetrics,
            'passed' => $candidateMetrics['accuracy'] >= $baseline['accuracy'],
            'completed_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * @param  array<string, string>  $overrides
     * @param  array<int, string>  $exampleIds
     * @param  array<int, array<string, mixed>>  $experimentRuns
     * @return array{accuracy: float, cases: array<int, array<string, mixed>>}
     */
    protected function replayVariant(
        EvaluationSuite $suite,
        array $overrides,
        string $variant,
        ?string $experimentId,
        array $exampleIds,
        array &$experimentRuns,
    ): array {
        $cases = [];
        $correct = 0;

        foreach ($suite->cases as $index => $case) {
            $start = now()->toImmutable();
            $expected = $case['expected']['accepted'] ?? null;

            try {
                $response = $this->evaluateCase($case['inputs'] ?? $case, $overrides);
                $accepted = (bool) $response['accepted'];
                $matches = $expected === null || $accepted === $expected;

                if ($matches) {
                    $correct++;
                }

                $cases[] = [
                    'case_index' => $index,
                    'accepted' => $accepted,
                    'confidence' => $response['confidence'] ?? null,
                    'expected' => $expected,
                    'matches' => $matches,
                ];

                $outputs = ['accepted' => $accepted, 'matches_expected' => $matches];
                $error = null;
            } catch (\Throwable $e) {
                $cases[] = [
                    'case_index' => $index,
                    'error' => $e->getMessage(),
                    'expected' => $expected,
                    'matches' => false,
                ];

                $outputs = null;
                $error = $e->getMessage();
            }

            if ($experimentId !== null) {
                $runId = (string) Str::uuid();
                $experimentRuns[] = array_filter([
                    'id' => $runId,
                    'trace_id' => $runId,
                    'dotted_order' => $start->format('Ymd\THis').$start->format('u').'Z'.$runId,
                    'name' => "buddy-replay-{$variant}-case-{$index}",
                    'run_type' => 'chain',
                    'start_time' => $start->format('Y-m-d\TH:i:s.u\Z'),
                    'end_time' => now()->toImmutable()->format('Y-m-d\TH:i:s.u\Z'),
                    'inputs' => ['case_index' => $index, 'variant' => $variant],
                    'outputs' => $outputs,
                    'error' => $error,
                    'session_id' => $experimentId,
                    'reference_example_id' => $exampleIds[$index] ?? null,
                    'extra' => ['metadata' => ['service' => 'buddy', 'variant' => $variant]],
                ], fn ($v) => $v !== null);
            }
        }

        $total = count($suite->cases);

        return [
            'accuracy' => $total > 0 ? round($correct / $total, 4) : 0.0,
            'cases' => $cases,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    protected function evaluateCase(array $inputs, array $overrides): array
    {
        $task = new BuddyTask;
        $task->ulid = 'replay-'.Str::lower(Str::random(12));
        $task->source_agent = 'cil-replay';
        $task->task_summary = (string) ($inputs['task_summary'] ?? '');
        $task->problem_type = ProblemType::tryFrom((string) ($inputs['problem_type'] ?? '')) ?? ProblemType::Other;
        $task->constraints = $inputs['constraints'] ?? [];
        $task->evidence = $inputs['evidence'] ?? [];
        $task->requested_outcome = $inputs['requested_outcome'] ?? null;

        $agent = new EvaluatorOptimizerAgent($task);

        if ($overrides !== []) {
            $agent->withBundle(
                $this->compiler->compile(EvaluatorOptimizerAgent::AGENT_KEY, $task, $overrides),
            );
        }

        $response = $agent->prompt($agent->buildPrompt());

        return [
            'accepted' => $response['accepted'],
            'confidence' => $response['confidence'] ?? null,
        ];
    }
}
