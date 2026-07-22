<?php

namespace App\Ai\Prompting;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Enums\ProblemType;
use App\Models\AgentProfile;
use RuntimeException;

class AgentProfileResolver
{
    /**
     * @return array{provider: string, model: string, timeout: int, max_steps: int, temperature: float}
     */
    public function resolve(string $agentKey, ?ProblemType $problemType = null): array
    {
        $defaults = config("buddy_agents.profiles.{$agentKey}");

        if (! is_array($defaults)) {
            throw new RuntimeException("Unknown agent profile: {$agentKey}");
        }

        $override = AgentProfile::query()
            ->where('name', $agentKey)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();

        // An active DB profile is the ops escape hatch: it wins verbatim
        // and suppresses problem-type routing (ADR 0008).
        if ($override !== null) {
            return [
                'provider' => $override->provider,
                'model' => $override->model,
                'timeout' => $override->timeout,
                'max_steps' => $override->max_steps,
                'temperature' => $override->temperature,
            ];
        }

        if ($this->routesToFastModel($agentKey, $problemType)) {
            $defaults['model'] = (string) config('buddy_agents.routing.fast_model');
        }

        return $defaults;
    }

    protected function routesToFastModel(string $agentKey, ?ProblemType $problemType): bool
    {
        if ($agentKey !== EvaluatorOptimizerAgent::AGENT_KEY || $problemType === null) {
            return false;
        }

        if (! config('buddy_agents.routing.enabled')) {
            return false;
        }

        return in_array($problemType->value, config('buddy_agents.routing.fast_problem_types', []), true);
    }
}
