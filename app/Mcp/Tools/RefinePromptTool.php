<?php

namespace App\Mcp\Tools;

use App\DTOs\ProblemPacket;
use App\Enums\ProblemType;
use App\Mcp\BaseMcpTool;
use App\Services\EvaluatorOptimizerService;

class RefinePromptTool extends BaseMcpTool
{
    public function name(): string
    {
        return 'buddy.refine_prompt';
    }

    public function description(): string
    {
        return 'Transform a vague or underspecified task request into a professional, '
            .'execution-ready engineering brief. Use this when your task is generic, '
            .'ambiguous, or needs structured refinement before execution.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_agent' => ['type' => 'string', 'description' => 'Identifier of the calling agent.'],
                'task_summary' => ['type' => 'string', 'description' => 'The raw, possibly vague task description to refine.'],
                'repo' => ['type' => 'string', 'description' => 'Repository identifier.'],
                'branch' => ['type' => 'string', 'description' => 'Branch name.'],
                'constraints' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Known constraints.'],
                'evidence' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Context: repo structure, stack, environment, capabilities. Do NOT include raw secrets.'],
                'requested_outcome' => ['type' => 'string', 'description' => 'What the calling agent wants from the refinement.'],
            ],
            'required' => ['source_agent', 'task_summary'],
        ];
    }

    public function handle(array $arguments): array
    {
        /** @var EvaluatorOptimizerService $evaluator */
        $evaluator = app(EvaluatorOptimizerService::class);

        $packet = new ProblemPacket(
            sourceAgent: $arguments['source_agent'],
            taskSummary: $arguments['task_summary'],
            problemType: ProblemType::PromptRefinement,
            repo: $arguments['repo'] ?? null,
            branch: $arguments['branch'] ?? null,
            constraints: $arguments['constraints'] ?? [],
            evidence: $arguments['evidence'] ?? [],
            requestedOutcome: $arguments['requested_outcome'] ?? 'Return a professional execution prompt with tool order, risks, constraints, and verification plan.',
        );

        $task = $evaluator->createTask($packet);
        $result = $evaluator->refine($task);

        return $this->textResponse(json_encode([
            'task_id' => $task->ulid,
            'accepted' => $result->accepted,
            'confidence' => $result->confidence->value,
            'summary' => $result->summary,
            'normalized_task' => $result->normalizedTask,
            'task_intent' => $result->taskIntent,
            'final_execution_prompt' => $result->finalExecutionPrompt,
            'clarified_constraints' => $result->clarifiedConstraints,
            'recommended_tool_sequence' => $result->recommendedToolSequence,
            'execution_checklist' => $result->executionChecklist,
            'risks' => $result->risks,
            'missing_information' => $result->missingInformation,
            'verification_plan' => $result->verificationPlan,
            'memory_hits' => $result->memoryHits,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>
     */
    protected function textResponse(string $text): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
    }
}
