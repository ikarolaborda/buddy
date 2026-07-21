<?php

namespace App\Ai\Prompting;

use App\Models\AgentProfile;
use RuntimeException;

class AgentProfileResolver
{
    /**
     * @return array{provider: string, model: string, timeout: int, max_steps: int, temperature: float}
     */
    public function resolve(string $agentKey): array
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

        if ($override === null) {
            return $defaults;
        }

        return [
            'provider' => $override->provider,
            'model' => $override->model,
            'timeout' => $override->timeout,
            'max_steps' => $override->max_steps,
            'temperature' => $override->temperature,
        ];
    }
}
