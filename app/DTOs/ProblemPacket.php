<?php

namespace App\DTOs;

use App\Enums\ProblemType;

readonly class ProblemPacket
{
    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<int, array<string, mixed>>  $attempts
     * @param  array<int, array<string, mixed>>  $evidence
     * @param  array<int, array<string, mixed>>  $artifacts
     */
    public function __construct(
        public string $sourceAgent,
        public string $taskSummary,
        public ProblemType $problemType,
        public ?string $repo = null,
        public ?string $branch = null,
        public array $constraints = [],
        public array $attempts = [],
        public array $evidence = [],
        public array $artifacts = [],
        public ?string $requestedOutcome = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceAgent: $data['source_agent'],
            taskSummary: $data['task_summary'],
            problemType: ProblemType::from($data['problem_type']),
            repo: $data['repo'] ?? null,
            branch: $data['branch'] ?? null,
            constraints: $data['constraints'] ?? [],
            attempts: $data['attempts'] ?? [],
            evidence: $data['evidence'] ?? [],
            artifacts: $data['artifacts'] ?? [],
            requestedOutcome: $data['requested_outcome'] ?? null,
        );
    }
}
