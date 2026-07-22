<?php

namespace App\Services\Observability;

use App\DTOs\EvaluationResult;
use App\DTOs\MemorySearchPage;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/*
 * Ships one completed run tree per evaluation to LangSmith's batch ingest
 * endpoint (POST /runs/batch). Tracing is a side-channel: every failure
 * is swallowed after a short timeout so it can never fail or delay an
 * evaluation. Prompt and summary content leaves the process only when
 * LANGSMITH_SEND_PROMPTS is enabled; the default payload carries hashes,
 * module IDs, token counts, memory IDs, and outcome metadata.
 */
class LangSmithTracer
{
    public function enabled(): bool
    {
        return (bool) config('buddy.langsmith.tracing')
            && (string) config('buddy.langsmith.api_key') !== '';
    }

    public function traceEvaluation(
        BuddyTask $task,
        BuddyRun $run,
        MemorySearchPage $memoryPage,
        ?EvaluationResult $result,
        ?\Throwable $error = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        try {
            [$payload, $rootId] = $this->buildRunTree($task, $run, $memoryPage, $result, $error);

            $this->post('/runs/batch', $payload);

            // The root run id is the feedback anchor: close-time outcome
            // feedback binds to it (ADR: LangSmith improvements).
            $run->forceFill(['langsmith_run_id' => $rootId])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('LangSmith trace dropped', ['error' => $e->getMessage()]);
        }
    }

    /*
     * Posts the task close outcome as feedback on the latest traced run,
     * regardless of run type (refinement-only tasks bind to refinement
     * traces). Deterministic feedback id makes racing closes idempotent
     * on the LangSmith side. Fire-and-forget like tracing.
     */
    public function sendTaskOutcomeFeedback(BuddyTask $task, string $outcome, ?int $score, ?string $notes): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $run = $task->runs()
                ->whereNotNull('langsmith_run_id')
                ->orderByDesc('run_number')
                ->first();

            if ($run === null) {
                return;
            }

            $this->post('/feedback', array_filter([
                'id' => Uuid::uuid5(Uuid::NAMESPACE_DNS, 'buddy:'.$task->ulid.':task_outcome')->toString(),
                'run_id' => $run->langsmith_run_id,
                'key' => 'task_outcome',
                'score' => $score !== null ? $score / 100 : null,
                'comment' => trim('outcome: '.$outcome.($notes !== null ? ' | '.$notes : '')),
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::warning('LangSmith feedback dropped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{0: array{post: array<int, array<string, mixed>>}, 1: string}
     */
    protected function buildRunTree(
        BuddyTask $task,
        BuddyRun $run,
        MemorySearchPage $memoryPage,
        ?EvaluationResult $result,
        ?\Throwable $error,
    ): array {
        $sendContent = (bool) config('buddy.langsmith.send_prompts');
        $start = $run->started_at?->toImmutable() ?? now()->toImmutable()->subSecond();
        $end = $run->completed_at?->toImmutable() ?? now()->toImmutable();

        $rootId = (string) Str::uuid();
        $rootDotted = $this->dottedOrder($start, $rootId);

        $rootInputs = [
            'task_ulid' => $task->ulid,
            'source_agent' => $task->source_agent,
            'problem_type' => $task->problem_type->value,
            'run_number' => $run->run_number,
            'prompt_hash' => $run->prompt_hash,
            'prompt_modules' => $run->prompt_modules,
        ];

        if ($sendContent) {
            $rootInputs['task_summary'] = $task->task_summary;
        }

        $rootOutputs = null;

        if ($result !== null) {
            $rootOutputs = [
                'accepted' => $result->accepted,
                'confidence' => $result->confidence->value,
                'memory_hit_count' => count($result->memoryHits),
            ];

            if ($sendContent) {
                $rootOutputs['summary'] = $result->summary;
            }
        }

        $root = $this->run(
            id: $rootId,
            traceId: $rootId,
            dottedOrder: $rootDotted,
            name: 'buddy.'.$run->run_type,
            runType: 'chain',
            start: $start,
            end: $end,
            inputs: $rootInputs,
            outputs: $rootOutputs,
            error: $error?->getMessage(),
            tags: [$task->problem_type->value, $run->run_type],
        );

        $retrieverStart = $start;
        $retrieverId = (string) Str::uuid();
        $retriever = $this->run(
            id: $retrieverId,
            traceId: $rootId,
            dottedOrder: $rootDotted.'.'.$this->dottedOrder($retrieverStart, $retrieverId),
            name: 'memory.search',
            runType: 'retriever',
            start: $retrieverStart,
            end: $retrieverStart->addSecond(),
            inputs: ['backend' => $memoryPage->backend],
            outputs: [
                'degraded' => $memoryPage->degraded,
                'degraded_reason' => $memoryPage->degradedReason,
                'memories' => array_map(fn ($r) => [
                    'memory_id' => $r->pointId,
                    'score' => $r->score,
                ], $memoryPage->results),
            ],
            error: null,
            tags: ['memory'],
            parentRunId: $rootId,
        );

        $llmId = (string) Str::uuid();
        $llm = $this->run(
            id: $llmId,
            traceId: $rootId,
            dottedOrder: $rootDotted.'.'.$this->dottedOrder($retrieverStart->addSecond(), $llmId),
            name: (string) ($run->model_used ?? 'llm'),
            runType: 'llm',
            start: $retrieverStart->addSecond(),
            end: $end,
            inputs: ['prompt_hash' => $run->prompt_hash],
            outputs: array_filter([
                'usage_metadata' => $this->usageMetadata($run->token_usage),
                'error_class' => $run->error_class,
            ], fn ($v) => $v !== null),
            error: $error?->getMessage(),
            tags: array_filter([(string) $run->provider]),
            parentRunId: $rootId,
            metadata: array_filter([
                'ls_provider' => $run->provider,
                'ls_model_name' => $run->model_used,
            ]),
        );

        return [['post' => [$root, $retriever, $llm]], $rootId];
    }

    /**
     * Maps laravel/ai usage keys onto LangSmith's usage_metadata contract
     * so token counts light up cost tracking.
     *
     * @param  array<string, int>|null  $tokenUsage
     * @return array<string, mixed>|null
     */
    protected function usageMetadata(?array $tokenUsage): ?array
    {
        if ($tokenUsage === null || $tokenUsage === []) {
            return null;
        }

        $input = (int) ($tokenUsage['prompt_tokens'] ?? 0);
        $output = (int) ($tokenUsage['completion_tokens'] ?? 0);

        return array_filter([
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'input_token_details' => array_filter([
                'cache_read' => (int) ($tokenUsage['cache_read_input_tokens'] ?? 0),
            ]),
            'output_token_details' => array_filter([
                'reasoning' => (int) ($tokenUsage['reasoning_tokens'] ?? 0),
            ]),
        ], fn ($v) => $v !== [] || ! is_array($v));
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>|null  $outputs
     * @param  array<int, string>  $tags
     * @return array<string, mixed>
     */
    protected function run(
        string $id,
        string $traceId,
        string $dottedOrder,
        string $name,
        string $runType,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        array $inputs,
        ?array $outputs,
        ?string $error,
        array $tags,
        ?string $parentRunId = null,
        array $metadata = [],
    ): array {
        return array_filter([
            'id' => $id,
            'trace_id' => $traceId,
            'parent_run_id' => $parentRunId,
            'dotted_order' => $dottedOrder,
            'name' => $name,
            'run_type' => $runType,
            'start_time' => $start->format('Y-m-d\TH:i:s.u\Z'),
            'end_time' => $end->format('Y-m-d\TH:i:s.u\Z'),
            'inputs' => $inputs,
            'outputs' => $outputs,
            'error' => $error,
            'session_name' => (string) config('buddy.langsmith.project'),
            'tags' => $tags,
            'extra' => ['metadata' => array_merge(['service' => 'buddy'], $metadata)],
        ], fn ($v) => $v !== null);
    }

    protected function dottedOrder(\DateTimeInterface $time, string $runId): string
    {
        return $time->format('Ymd\THis').$time->format('u').'Z'.$runId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $path, array $payload): void
    {
        Http::baseUrl((string) config('buddy.langsmith.endpoint'))
            ->withHeaders(['x-api-key' => (string) config('buddy.langsmith.api_key')])
            ->timeout((int) config('buddy.langsmith.timeout', 2))
            ->connectTimeout(2)
            ->asJson()
            ->post($path, $payload);
    }
}
