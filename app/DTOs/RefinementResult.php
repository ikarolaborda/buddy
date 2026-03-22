<?php

namespace App\DTOs;

use App\Enums\Confidence;

readonly class RefinementResult
{
    /**
     * @param  array<int, string>  $clarifiedConstraints
     * @param  array<int, string>  $recommendedToolSequence
     * @param  array<int, string>  $executionChecklist
     * @param  array<int, string>  $risks
     * @param  array<int, string>  $missingInformation
     * @param  array<int, string>  $verificationPlan
     * @param  array<int, string>  $memoryHits
     */
    public function __construct(
        public bool $accepted,
        public Confidence $confidence,
        public string $summary,
        public string $normalizedTask,
        public string $taskIntent,
        public string $finalExecutionPrompt,
        public array $clarifiedConstraints = [],
        public array $recommendedToolSequence = [],
        public array $executionChecklist = [],
        public array $risks = [],
        public array $missingInformation = [],
        public array $verificationPlan = [],
        public array $memoryHits = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accepted: (bool) $data['accepted'],
            confidence: Confidence::from($data['confidence']),
            summary: $data['summary'],
            normalizedTask: $data['normalized_task'],
            taskIntent: $data['task_intent'],
            finalExecutionPrompt: $data['final_execution_prompt'],
            clarifiedConstraints: $data['clarified_constraints'] ?? [],
            recommendedToolSequence: $data['recommended_tool_sequence'] ?? [],
            executionChecklist: $data['execution_checklist'] ?? [],
            risks: $data['risks'] ?? [],
            missingInformation: $data['missing_information'] ?? [],
            verificationPlan: $data['verification_plan'] ?? [],
            memoryHits: $data['memory_hits'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'confidence' => $this->confidence->value,
            'summary' => $this->summary,
            'normalized_task' => $this->normalizedTask,
            'task_intent' => $this->taskIntent,
            'final_execution_prompt' => $this->finalExecutionPrompt,
            'clarified_constraints' => $this->clarifiedConstraints,
            'recommended_tool_sequence' => $this->recommendedToolSequence,
            'execution_checklist' => $this->executionChecklist,
            'risks' => $this->risks,
            'missing_information' => $this->missingInformation,
            'verification_plan' => $this->verificationPlan,
            'memory_hits' => $this->memoryHits,
        ];
    }
}
