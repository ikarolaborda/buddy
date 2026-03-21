<?php

namespace App\Services;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\DTOs\EvaluationResult;
use App\DTOs\MemorySearchResult;
use App\DTOs\ProblemPacket;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\BuddyDecisionLog;
use App\Models\BuddyRecommendation;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EvaluatorOptimizerService
{
    public function __construct(
        protected QdrantMemoryService $memoryService,
    ) {}

    public function createTask(ProblemPacket $packet): BuddyTask
    {
        return BuddyTask::create([
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
        if ($task->isTerminal()) {
            throw new \RuntimeException("Task {$task->ulid} is already in terminal state: {$task->status->value}");
        }

        $task->update(['status' => TaskStatus::Evaluating]);

        $run = $this->createRun($task);

        try {
            $memoryHits = $this->searchMemory($task);
            $this->storeMemoryReferences($task, $memoryHits);

            $result = $this->runAgent($task);

            $this->storeRecommendation($run, $result);
            $this->logDecision($task, $run, $result);

            $run->update([
                'status' => RunStatus::Completed,
                'completed_at' => now(),
            ]);

            $task->update(['status' => TaskStatus::Completed]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Evaluation failed', [
                'task_ulid' => $task->ulid,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => RunStatus::Failed,
                'completed_at' => now(),
            ]);

            $task->update(['status' => TaskStatus::Failed]);

            throw $e;
        }
    }

    public function closeTask(BuddyTask $task, ?string $learningsSummary = null): void
    {
        DB::transaction(function () use ($task, $learningsSummary) {
            $task->update(['status' => TaskStatus::Closed]);

            if ($learningsSummary) {
                $this->storeLearnings($task, $learningsSummary);
            }
        });
    }

    protected function createRun(BuddyTask $task): BuddyRun
    {
        $runNumber = $task->runs()->count() + 1;

        return BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => $runNumber,
            'status' => RunStatus::Started,
            'model_used' => config('buddy.model', 'gpt-5.4'),
            'started_at' => now(),
        ]);
    }

    protected function runAgent(BuddyTask $task): EvaluationResult
    {
        $agent = new EvaluatorOptimizerAgent($task);
        $prompt = $agent->buildPrompt();

        $response = $agent->prompt($prompt);

        return EvaluationResult::fromArray([
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
    }

    /**
     * @return array<int, MemorySearchResult>
     */
    protected function searchMemory(BuddyTask $task): array
    {
        $query = "{$task->problem_type->value}: {$task->task_summary}";

        return $this->memoryService->search($query);
    }

    /**
     * @param  array<int, MemorySearchResult>  $memoryHits
     */
    protected function storeMemoryReferences(BuddyTask $task, array $memoryHits): void
    {
        foreach ($memoryHits as $hit) {
            $task->memoryReferences()->create([
                'qdrant_point_id' => $hit->pointId,
                'similarity_score' => $hit->score,
                'memory_summary' => $hit->summary,
                'tags' => $hit->tags,
            ]);
        }
    }

    protected function storeRecommendation(BuddyRun $run, EvaluationResult $result): BuddyRecommendation
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
        ]);
    }

    protected function logDecision(BuddyTask $task, BuddyRun $run, EvaluationResult $result): void
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
            'tags' => [
                $task->problem_type->value,
                'buddy_learning',
            ],
        ];

        if ($task->repo) {
            $payload['repo'] = $task->repo;
        }

        $this->memoryService->store($summary, $payload);
    }
}
