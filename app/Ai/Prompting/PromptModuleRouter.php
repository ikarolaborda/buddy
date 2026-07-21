<?php

namespace App\Ai\Prompting;

use App\Enums\ProblemType;
use App\Models\BuddyTask;

class PromptModuleRouter
{
    /**
     * Deterministic routing from problem signals to domain modules. A
     * classifier is intentionally not used here; ambiguous tasks receive
     * the general-purpose stack + fundamentals pair.
     *
     * @return array<int, string>
     */
    public function domainsFor(BuddyTask $task): array
    {
        return match ($task->problem_type) {
            ProblemType::Security => ['domains/cybersecurity', 'domains/technology-stacks'],
            ProblemType::Performance => ['domains/performance-and-memory', 'domains/computer-science'],
            ProblemType::Architecture => ['domains/computer-science', 'domains/technology-stacks'],
            ProblemType::Bug,
            ProblemType::TestFailure,
            ProblemType::Integration,
            ProblemType::Configuration => ['domains/technology-stacks'],
            ProblemType::PromptRefinement => [],
            ProblemType::Ambiguous,
            ProblemType::Other => ['domains/technology-stacks', 'domains/computer-science'],
        };
    }
}
