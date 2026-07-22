<?php

namespace App\Services\Council;

use App\Models\BuddyTask;

/*
 * Mechanical eligibility gate for council invocation (ADR 0009 addendum).
 * A council run is ~12 frontier-model calls over 4 sequential rounds
 * (2-10 minutes, dollars not cents), so it is reserved for subjects that
 * have either objectively earned escalation (task distress markers) or
 * are explicitly declared critical with a substantive reason. The gate
 * is computed in PHP from task state, never trusted from prose alone;
 * declared criticality is the audited escape hatch for first-touch
 * critical subjects the markers cannot see.
 */
class CouncilGate
{
    /**
     * @return array{allowed: bool, basis: string, markers: array<int, string>, message: string}
     */
    public function evaluate(BuddyTask $task, ?string $criticality, ?string $reason): array
    {
        if (! config('buddy_agents.council.gate_enabled')) {
            return $this->allowed('gate_disabled', []);
        }

        if ($task->runs()->where('run_type', 'council')->exists()) {
            return [
                'allowed' => false,
                'basis' => 'council_already_convened',
                'markers' => [],
                'message' => 'This task already had a council deliberation; one council per task. Submit a new task with the updated evidence.',
            ];
        }

        $markers = $this->markers($task);

        if ($markers !== []) {
            return $this->allowed('markers', $markers);
        }

        $reason = trim((string) $reason);
        $minReason = (int) config('buddy_agents.council.gate_min_reason_length');

        if ($criticality === 'critical' && mb_strlen($reason) >= $minReason) {
            return $this->allowed('declared_critical', []);
        }

        return [
            'allowed' => false,
            'basis' => 'not_eligible',
            'markers' => [],
            'message' => 'Council is reserved for critical subjects. Either run buddy.evaluate_task first and escalate after a failed or rejected evaluation, or pass criticality="critical" with a reason of at least '
                .$minReason.' characters explaining why this cannot be missed.',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function markers(BuddyTask $task): array
    {
        $markers = [];
        $attemptThreshold = (int) config('buddy_agents.council.gate_attempt_threshold');

        if ($task->attempt_count >= $attemptThreshold) {
            $markers[] = 'attempt_count>='.$attemptThreshold;
        }

        if ($task->runs()->where('status', 'failed')->exists()) {
            $markers[] = 'failed_run';
        }

        $rejected = $task->runs()
            ->whereHas('recommendation', fn ($query) => $query->where('accepted', false))
            ->exists();

        if ($rejected) {
            $markers[] = 'rejected_evaluation';
        }

        return $markers;
    }

    /**
     * @param  array<int, string>  $markers
     * @return array{allowed: bool, basis: string, markers: array<int, string>, message: string}
     */
    protected function allowed(string $basis, array $markers): array
    {
        return [
            'allowed' => true,
            'basis' => $basis,
            'markers' => $markers,
            'message' => '',
        ];
    }
}
