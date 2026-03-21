<?php

namespace App\Services;

use App\DTOs\EscalationTrigger;

class EscalationService
{
    public function evaluate(EscalationTrigger $trigger): bool
    {
        return $trigger->shouldEscalate();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function evaluateFromArray(array $data): bool
    {
        return EscalationTrigger::fromArray($data)->shouldEscalate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function analyzeFromArray(array $data): array
    {
        $trigger = EscalationTrigger::fromArray($data);

        return [
            'should_escalate' => $trigger->shouldEscalate(),
            'active_condition_count' => $trigger->activeConditionCount(),
            'active_conditions' => $trigger->activeConditions(),
            'min_triggers_required' => config('buddy.escalation.min_triggers', 2),
        ];
    }
}
