<?php

namespace App\DTOs;

use App\Enums\Confidence;

readonly class EvaluationResult
{
    /**
     * @param  array<int, string>  $recommendedPlan
     * @param  array<int, string>  $rejectedReasons
     * @param  array<int, string>  $requiredFollowups
     * @param  array<int, string>  $risks
     * @param  array<int, string>  $nextActions
     * @param  array<int, array<string, mixed>>  $memoryHits
     */
    public function __construct(
        public bool $accepted,
        public Confidence $confidence,
        public string $summary,
        public array $recommendedPlan = [],
        public array $rejectedReasons = [],
        public array $requiredFollowups = [],
        public array $risks = [],
        public array $nextActions = [],
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
            recommendedPlan: $data['recommended_plan'] ?? [],
            rejectedReasons: $data['rejected_reasons'] ?? [],
            requiredFollowups: $data['required_followups'] ?? [],
            risks: $data['risks'] ?? [],
            nextActions: $data['next_actions'] ?? [],
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
            'recommended_plan' => $this->recommendedPlan,
            'rejected_reasons' => $this->rejectedReasons,
            'required_followups' => $this->requiredFollowups,
            'risks' => $this->risks,
            'next_actions' => $this->nextActions,
            'memory_hits' => $this->memoryHits,
        ];
    }
}
