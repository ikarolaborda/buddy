<?php

namespace App\Services\Observability;

use App\DTOs\EvaluationResult;
use App\DTOs\MemorySearchPage;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            $this->post($this->buildRunTree($task, $run, $memoryPage, $result, $error));
        } catch (\Throwable $e) {
            Log::warning('LangSmith trace dropped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{post: array<int, array<string, mixed>>}
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
            outputs: [
                'token_usage' => $run->token_usage,
                'error_class' => $run->error_class,
            ],
            error: $error?->getMessage(),
            tags: array_filter([(string) $run->provider]),
            parentRunId: $rootId,
        );

        return ['post' => [$root, $retriever, $llm]];
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
            'extra' => ['metadata' => ['service' => 'buddy']],
        ], fn ($v) => $v !== null);
    }

    protected function dottedOrder(\DateTimeInterface $time, string $runId): string
    {
        return $time->format('Ymd\THis').$time->format('u').'Z'.$runId;
    }

    /**
     * @param  array{post: array<int, array<string, mixed>>}  $payload
     */
    protected function post(array $payload): void
    {
        Http::baseUrl((string) config('buddy.langsmith.endpoint'))
            ->withHeaders(['x-api-key' => (string) config('buddy.langsmith.api_key')])
            ->timeout((int) config('buddy.langsmith.timeout', 2))
            ->connectTimeout(2)
            ->asJson()
            ->post('/runs/batch', $payload);
    }
}
