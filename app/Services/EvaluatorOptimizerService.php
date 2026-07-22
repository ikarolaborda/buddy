<?php

namespace App\Services;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Ai\Agents\PromptRefinementAgent;
use App\Ai\Prompting\AgentProfileResolver;
use App\Contracts\MemoryGateway;
use App\DTOs\EvaluationResult;
use App\DTOs\MemoryCandidate;
use App\DTOs\MemoryQuery;
use App\DTOs\MemorySearchPage;
use App\DTOs\ProblemPacket;
use App\DTOs\RefinementResult;
use App\Enums\ArtifactType;
use App\Enums\RunStatus;
use App\Enums\TaskOutcome;
use App\Enums\TaskStatus;
use App\Models\BuddyDecisionLog;
use App\Models\BuddyRecommendation;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Models\PromptVersion;
use App\Models\TaskFeedback;
use App\Services\Council\CouncilService;
use App\Services\Observability\LangSmithTracer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EvaluatorOptimizerService
{
    public function __construct(
        protected MemoryGateway $memory,
        protected TaskStateService $state,
        protected AgentProfileResolver $profiles,
        protected LangSmithTracer $tracer,
    ) {}

    public function createTask(ProblemPacket $packet, ?int $apiClientId = null): BuddyTask
    {
        return BuddyTask::create([
            'api_client_id' => $apiClientId,
            'source_agent' => $packet->sourceAgent,
            'repo' => $packet->repo,
            'branch' => $packet->branch,
            'task_summary' => $packet->taskSummary,
            'problem_type' => $packet->problemType,
            'constraints' => $packet->constraints,
            'evidence' => $packet->evidence,
            'requested_outcome' => $packet->requestedOutcome,
            'status' => TaskStatus::Pending,
            'attempt_count' => count($packet->attempts),
        ]);
    }

    public function evaluate(BuddyTask $task): EvaluationResult
    {
        return $this->executeRun($task, 'evaluation', function (BuddyTask $task, BuddyRun $run) {
            $agent = new EvaluatorOptimizerAgent($task);
            $this->recordRunConfiguration($run, EvaluatorOptimizerAgent::AGENT_KEY, $agent->promptBundle()->contentHash, $agent->promptBundle()->moduleIds);

            $response = $agent->prompt($agent->buildPrompt());

            $result = EvaluationResult::fromArray([
                'accepted' => $response['accepted'],
                'confidence' => $response['confidence'],
                'summary' => $response['summary'],
                'recommended_plan' => $response['recommended_plan'] ?? [],
                'rejected_reasons' => $response['rejected_reasons'] ?? [],
                'required_followups' => $response['required_followups'] ?? [],
                'risks' => $response['risks'] ?? [],
                'next_actions' => $response['next_actions'] ?? [],
                'memory_hits' => $response['memory_hits'] ?? [],
            ]);

            return [$result, $result, null, $response->usage->toArray()];
        });
    }

    public function refine(BuddyTask $task): RefinementResult
    {
        return $this->executeRun($task, 'refinement', function (BuddyTask $task, BuddyRun $run) {
            $agent = new PromptRefinementAgent($task);
            $this->recordRunConfiguration($run, PromptRefinementAgent::AGENT_KEY, $agent->promptBundle()->contentHash, $agent->promptBundle()->moduleIds);

            $response = $agent->prompt($agent->buildPrompt());

            $result = RefinementResult::fromArray([
                'accepted' => $response['accepted'],
                'confidence' => $response['confidence'],
                'summary' => $response['summary'],
                'normalized_task' => $response['normalized_task'],
                'task_intent' => $response['task_intent'],
                'final_execution_prompt' => $response['final_execution_prompt'],
                'clarified_constraints' => $response['clarified_constraints'] ?? [],
                'recommended_tool_sequence' => $response['recommended_tool_sequence'] ?? [],
                'execution_checklist' => $response['execution_checklist'] ?? [],
                'risks' => $response['risks'] ?? [],
                'missing_information' => $response['missing_information'] ?? [],
                'verification_plan' => $response['verification_plan'] ?? [],
                'memory_hits' => $response['memory_hits'] ?? [],
            ]);

            return [$result, $this->refinementToEvaluation($result), $result->toArray(), $response->usage->toArray()];
        });
    }

    public function council(BuddyTask $task, ?string $claimOwner = null): array
    {
        return $this->executeRun($task, 'council', function (BuddyTask $task, BuddyRun $run, MemorySearchPage $memoryPage) use ($claimOwner) {
            $result = app(CouncilService::class)->deliberate($task, $memoryPage, $claimOwner);

            $task->artifacts()->create([
                'type' => ArtifactType::CouncilTranscript,
                'content' => (string) json_encode($result['transcript']),
                'metadata' => ['run_id' => $run->id],
            ]);

            $verdict = $result['verdict'];

            $projection = EvaluationResult::fromArray([
                'accepted' => (bool) $verdict['accepted'],
                'confidence' => in_array($verdict['confidence'], ['high', 'medium', 'low', 'none'], true) ? $verdict['confidence'] : 'low',
                'summary' => $verdict['summary'],
                'recommended_plan' => $verdict['recommended_plan'],
                'rejected_reasons' => $verdict['defeated'],
                'required_followups' => $verdict['proposed_discriminators'],
                'risks' => $verdict['risks'],
                'next_actions' => $verdict['recommended_plan'],
                'memory_hits' => [],
            ]);

            return [$verdict, $projection, null, $result['usage'], $verdict];
        });
    }

    public function closeTask(
        BuddyTask $task,
        ?string $learningsSummary = null,
        ?TaskOutcome $outcome = null,
        ?string $notes = null,
    ): void {
        // Feedback rides the transition transaction so a racing double
        // close (loser throws on the atomic CAS) cannot double-insert.
        DB::transaction(function () use ($task, $outcome, $notes) {
            $this->state->transition($task, TaskStatus::Closed);

            if ($outcome !== null) {
                TaskFeedback::create([
                    'buddy_task_id' => $task->id,
                    'outcome' => $outcome->value,
                    'score' => $outcome->score(),
                    'comment' => $notes,
                    'source' => 'agent_close',
                ]);
            }
        });

        if ($learningsSummary) {
            $this->storeLearnings($task, $learningsSummary);
        }

        if ($outcome !== null) {
            $this->tracer->sendTaskOutcomeFeedback($task, $outcome->value, $outcome->score(), $notes);
        }
    }

    /**
     * The agent/network call runs outside any database transaction; only
     * result persistence is transactional. Callback returns
     * [domain result, evaluation projection, refinement payload|null,
     * token usage|null].
     */
    protected function executeRun(BuddyTask $task, string $runType, callable $callback): mixed
    {
        if ($task->isTerminal()) {
            throw new \RuntimeException("Task {$task->ulid} is already in terminal state: {$task->status->value}");
        }

        if ($task->status === TaskStatus::Pending) {
            $this->state->transition($task, TaskStatus::Evaluating);
        }

        $run = $this->createRun($task, $runType);

        try {
            $memoryPage = $this->searchMemory($task);
            $this->storeMemoryReferences($task, $memoryPage);

            [$domainResult, $evaluation, $refinementPayload, $tokenUsage, $councilPayload] = array_pad($callback($task, $run, $memoryPage), 5, null);

            DB::transaction(function () use ($task, $run, $evaluation, $memoryPage, $refinementPayload, $tokenUsage, $councilPayload) {
                $this->storeRecommendation($run, $evaluation, $refinementPayload, $councilPayload);
                $this->logDecision($task, $run, $evaluation, $memoryPage);

                $run->update([
                    'status' => RunStatus::Completed,
                    'token_usage' => $tokenUsage,
                    'completed_at' => now(),
                ]);

                $this->state->transition($task, TaskStatus::Completed);
            });

            $this->tracer->traceEvaluation($task, $run->refresh(), $memoryPage, $evaluation);

            return $domainResult;
        } catch (\Throwable $e) {
            Log::error(ucfirst($runType).' failed', [
                'task_ulid' => $task->ulid,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => RunStatus::Failed,
                'error_class' => $e::class,
                'completed_at' => now(),
            ]);

            if (! $task->isTerminal() && $task->status === TaskStatus::Evaluating) {
                $this->state->transition($task, TaskStatus::Failed);
            }

            $this->tracer->traceEvaluation(
                $task,
                $run->refresh(),
                $memoryPage ?? MemorySearchPage::degraded('unknown', 'run failed before memory search'),
                null,
                $e,
            );

            throw $e;
        }
    }

    protected function refinementToEvaluation(RefinementResult $result): EvaluationResult
    {
        return new EvaluationResult(
            accepted: $result->accepted,
            confidence: $result->confidence,
            summary: $result->summary,
            recommendedPlan: $result->executionChecklist,
            rejectedReasons: [],
            requiredFollowups: $result->missingInformation,
            risks: $result->risks,
            nextActions: $result->recommendedToolSequence,
            memoryHits: $result->memoryHits,
        );
    }

    protected function createRun(BuddyTask $task, string $runType): BuddyRun
    {
        return DB::transaction(function () use ($task, $runType) {
            $locked = BuddyTask::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->first();

            $runNumber = $locked->runs()->max('run_number') + 1;

            return BuddyRun::create([
                'buddy_task_id' => $task->id,
                'run_number' => $runNumber,
                'run_type' => $runType,
                'status' => RunStatus::Started,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<int, string>  $moduleIds
     */
    protected function recordRunConfiguration(BuddyRun $run, string $agentKey, string $promptHash, array $moduleIds): void
    {
        // The resolver routes only the evaluator agent, so passing the
        // problem type is a no-op for the refiner and keeps recorded
        // model_used identical to the model each agent actually calls.
        $profile = $this->profiles->resolve($agentKey, $run->task->problem_type);

        PromptVersion::firstOrCreate(
            ['agent' => $agentKey, 'content_hash' => $promptHash],
            ['module_ids' => $moduleIds, 'module_hashes' => []],
        );

        $run->update([
            'model_used' => $profile['model'],
            'provider' => $profile['provider'],
            'prompt_hash' => $promptHash,
            'prompt_modules' => $moduleIds,
        ]);
    }

    protected function searchMemory(BuddyTask $task): MemorySearchPage
    {
        $query = "{$task->problem_type->value}: {$task->task_summary}";

        return $this->memory->search(new MemoryQuery($query));
    }

    protected function storeMemoryReferences(BuddyTask $task, MemorySearchPage $page): void
    {
        foreach ($page->results as $hit) {
            $task->memoryReferences()->create([
                'qdrant_point_id' => $hit->pointId,
                'memory_id' => $hit->pointId,
                'backend' => $page->backend,
                'similarity_score' => $hit->score,
                'memory_summary' => $hit->summary,
                'tags' => $hit->tags,
            ]);
        }
    }

    protected function storeRecommendation(BuddyRun $run, EvaluationResult $result, ?array $refinementPayload, ?array $councilPayload = null): BuddyRecommendation
    {
        return BuddyRecommendation::create([
            'buddy_run_id' => $run->id,
            'accepted' => $result->accepted,
            'confidence' => $result->confidence,
            'summary' => $result->summary,
            'recommended_plan' => $result->recommendedPlan,
            'rejected_reasons' => $result->rejectedReasons,
            'required_followups' => $result->requiredFollowups,
            'risks' => $result->risks,
            'next_actions' => $result->nextActions,
            'memory_hits' => $result->memoryHits,
            'refinement' => $refinementPayload,
            'council' => $councilPayload,
        ]);
    }

    protected function logDecision(BuddyTask $task, BuddyRun $run, EvaluationResult $result, MemorySearchPage $memoryPage): void
    {
        BuddyDecisionLog::create([
            'buddy_task_id' => $task->id,
            'buddy_run_id' => $run->id,
            'decision_type' => $result->accepted ? 'recommendation_accepted' : 'recommendation_rejected',
            'rationale' => $result->summary,
            'evidence' => [
                'confidence' => $result->confidence->value,
                'risks_count' => count($result->risks),
                'memory_hits_count' => count($result->memoryHits),
                'memory_backend' => $memoryPage->backend,
                'memory_degraded' => $memoryPage->degraded,
                'memory_degraded_reason' => $memoryPage->degradedReason,
            ],
        ]);
    }

    protected function storeLearnings(BuddyTask $task, string $summary): void
    {
        $recommendation = $task->latestRecommendation();

        $payload = [
            'task_intent' => $task->problem_type->value,
            'source_agent' => $task->source_agent,
            'outcome' => $recommendation?->accepted ? 'accepted' : 'rejected',
            'confidence' => $recommendation?->confidence->value ?? 'none',
        ];

        if ($task->repo) {
            $payload['repo'] = $task->repo;
        }

        $this->memory->store(new MemoryCandidate(
            summary: $summary,
            tags: [$task->problem_type->value, 'buddy_learning'],
            payload: $payload,
            problem: $task->task_summary,
            solution: $summary,
            impact: 'Outcome: '.($payload['outcome'] ?? 'unknown').' (confidence: '.($payload['confidence'] ?? 'none').')',
        ));
    }
}
