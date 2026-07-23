<?php

namespace App\Services\Cil;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Ai\Prompting\AgentProfileResolver;
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
        protected AgentProfileResolver $profiles,
    ) {}

    public function replay(ImprovementCandidate $candidate, EvaluationSuite $suite): EvaluationRun
    {
        if (! in_array($candidate->kind, ['prompt', 'model'], true)) {
            throw new RuntimeException("Only prompt or model candidates can be replayed; got '{$candidate->kind}'.");
        }

        $overrides = [];
        $baselineModel = null;
        $candidateModel = null;

        if ($candidate->kind === 'prompt') {
            $overrides = $candidate->payload['modules'] ?? [];

            if ($overrides === []) {
                throw new RuntimeException('Candidate payload has no module overrides.');
            }
        }

        if ($candidate->kind === 'model') {
            $candidateModel = (string) ($candidate->payload['model'] ?? '');

            if ($candidateModel === '') {
                throw new RuntimeException('Model candidate payload has no model.');
            }

            // Both legs must be pinned or problem-type routing decides the
            // model per case and contaminates the A/B. Baseline resolves
            // with a null problem type: routing off, DB override honored.
            $baselineModel = $this->profiles
                ->resolve(EvaluatorOptimizerAgent::AGENT_KEY, null)['model'];
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

        $baseline = $this->replayVariant($suite, [], 'baseline', $experimentId, $exampleIds, $experimentRuns, $baselineModel);
        $candidateMetrics = $this->replayVariant($suite, $overrides, 'candidate', $experimentId, $exampleIds, $experimentRuns, $candidateModel);

        $this->langsmith->postExperimentRuns($experimentRuns);
        $this->langsmith->endExperiment($run->refresh());

        // Accuracy stays primary; the calibration-aware graded score breaks
        // ties so equal-accuracy candidates still have to earn the pass.
        $passed = $candidateMetrics['accuracy'] > $baseline['accuracy']
            || ($candidateMetrics['accuracy'] === $baseline['accuracy']
                && $candidateMetrics['graded_score'] >= $baseline['graded_score']);

        $run->update([
            'baseline_metrics' => $baseline,
            'candidate_metrics' => $candidateMetrics,
            'passed' => $passed,
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
        ?string $model = null,
    ): array {
        $cases = [];
        $correct = 0;
        $graded = 0.0;

        foreach ($suite->cases as $index => $case) {
            $start = now()->toImmutable();
            $expected = $case['expected']['accepted'] ?? null;

            try {
                $response = $this->evaluateCase($case['inputs'] ?? $case, $overrides, $model);
                $accepted = (bool) $response['accepted'];
                $matches = $expected === null || $accepted === $expected;

                if ($matches) {
                    $correct++;
                }

                $weight = $this->confidenceWeight($response['confidence'] ?? null);
                $caseScore = $matches ? $weight : 1.0 - $weight;
                $graded += $caseScore;

                $cases[] = [
                    'case_index' => $index,
                    'accepted' => $accepted,
                    'confidence' => $response['confidence'] ?? null,
                    'expected' => $expected,
                    'matches' => $matches,
                    'graded_score' => round($caseScore, 4),
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
            'graded_score' => $total > 0 ? round($graded / $total, 4) : 0.0,
            'cases' => $cases,
        ];
    }

    /*
     * Brier-flavored calibration weight: being confidently right scores
     * highest, confidently wrong lowest; errored cases contribute zero.
     */
    protected function confidenceWeight(?string $confidence): float
    {
        return match ($confidence) {
            'high' => 1.0,
            'medium' => 0.75,
            'low' => 0.5,
            default => 0.25,
        };
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    protected function evaluateCase(array $inputs, array $overrides, ?string $model = null): array
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

        $response = $agent->prompt($agent->buildPrompt(), model: $model);

        return [
            'accepted' => $response['accepted'],
            'confidence' => $response['confidence'] ?? null,
        ];
    }
}
