<?php

namespace App\DTOs;

readonly class EscalationTrigger
{
    public function __construct(
        public int $elapsedSeconds = 0,
        public int $failedAttempts = 0,
        public bool $repeatedTestFailure = false,
        public bool $lowConfidence = false,
        public bool $rootCauseAmbiguous = false,
        public bool $evidenceConflicts = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            elapsedSeconds: (int) ($data['elapsed_seconds'] ?? 0),
            failedAttempts: (int) ($data['failed_attempts'] ?? 0),
            repeatedTestFailure: (bool) ($data['repeated_test_failure'] ?? false),
            lowConfidence: (bool) ($data['low_confidence'] ?? false),
            rootCauseAmbiguous: (bool) ($data['root_cause_ambiguous'] ?? false),
            evidenceConflicts: (bool) ($data['evidence_conflicts'] ?? false),
        );
    }

    public function activeConditionCount(): int
    {
        $count = 0;

        if ($this->elapsedSeconds > config('buddy.escalation.max_elapsed_seconds', 300)) {
            $count++;
        }
        if ($this->failedAttempts > config('buddy.escalation.max_failed_attempts', 2)) {
            $count++;
        }
        if ($this->repeatedTestFailure) {
            $count++;
        }
        if ($this->lowConfidence) {
            $count++;
        }
        if ($this->rootCauseAmbiguous) {
            $count++;
        }
        if ($this->evidenceConflicts) {
            $count++;
        }

        return $count;
    }

    public function shouldEscalate(): bool
    {
        return $this->activeConditionCount() >= config('buddy.escalation.min_triggers', 2);
    }

    /**
     * @return array<int, string>
     */
    public function activeConditions(): array
    {
        $conditions = [];

        if ($this->elapsedSeconds > config('buddy.escalation.max_elapsed_seconds', 300)) {
            $conditions[] = 'time_elapsed';
        }
        if ($this->failedAttempts > config('buddy.escalation.max_failed_attempts', 2)) {
            $conditions[] = 'failed_attempts';
        }
        if ($this->repeatedTestFailure) {
            $conditions[] = 'repeated_test_failure';
        }
        if ($this->lowConfidence) {
            $conditions[] = 'low_confidence';
        }
        if ($this->rootCauseAmbiguous) {
            $conditions[] = 'root_cause_ambiguous';
        }
        if ($this->evidenceConflicts) {
            $conditions[] = 'evidence_conflicts';
        }

        return $conditions;
    }
}
