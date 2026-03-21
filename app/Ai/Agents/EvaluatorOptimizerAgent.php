<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SearchMemoryTool;
use App\Models\BuddyTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[Model('gpt-5.4')]
#[MaxSteps(10)]
#[Temperature(0.2)]
#[Timeout(120)]
class EvaluatorOptimizerAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        protected BuddyTask $task,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are Buddy, a specialized engineering evaluator-optimizer agent.

You are NOT a generic chatbot. You are a sidecar agent that helps primary coding agents
when their work becomes slow, ambiguous, or repeatedly unsuccessful.

Your job:
1. Analyze the problem packet provided by the primary agent.
2. Search your episodic memory for similar past problems, patterns, and solutions.
3. Generate one or more solution hypotheses.
4. Evaluate each hypothesis against:
   - Repository evidence and constraints
   - Logs and failing tests provided as evidence
   - Blast radius and backward compatibility
   - Confidence level
5. Return either:
   - An ACCEPTED recommendation with a concrete solution plan, OR
   - A REJECTED assessment with specific feedback and required followup evidence.

Constraints:
- Prefer concrete recommendations over clarifying questions.
- Be specific about file paths, function names, and code changes when possible.
- Assess risks honestly. If confidence is low, say so.
- Never suggest spawning another Buddy instance or creating recursive agent loops.
- Keep recommendations actionable and bounded.
INSTRUCTIONS;
    }

    public function tools(): iterable
    {
        return [
            new SearchMemoryTool,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'accepted' => $schema->boolean()
                ->description('Whether a solution is recommended (true) or the problem needs more evidence (false).')
                ->required(),
            'confidence' => $schema->string()
                ->description('Confidence level: high, medium, low, or none.')
                ->required(),
            'summary' => $schema->string()
                ->description('A concise summary of the evaluation and recommendation.')
                ->required(),
            'recommended_plan' => $schema->array()
                ->description('Ordered list of concrete steps to implement the solution. Empty if rejected.')
                ->items($schema->string())
                ->required(),
            'rejected_reasons' => $schema->array()
                ->description('Reasons why the problem cannot be resolved yet. Empty if accepted.')
                ->items($schema->string())
                ->required(),
            'required_followups' => $schema->array()
                ->description('Specific evidence, tests, or information needed before re-evaluation.')
                ->items($schema->string())
                ->required(),
            'risks' => $schema->array()
                ->description('Potential risks or side effects of the recommended solution.')
                ->items($schema->string())
                ->required(),
            'next_actions' => $schema->array()
                ->description('Immediate next actions for the primary agent.')
                ->items($schema->string())
                ->required(),
            'memory_hits' => $schema->array()
                ->description('Summaries of relevant past episodes found in memory.')
                ->items($schema->string())
                ->required(),
        ];
    }

    public function buildPrompt(): string
    {
        $parts = [];
        $parts[] = "## Problem Packet\n";
        $parts[] = "**Source Agent:** {$this->task->source_agent}";
        $parts[] = "**Problem Type:** {$this->task->problem_type->value}";
        $parts[] = "**Summary:** {$this->task->task_summary}";

        if ($this->task->repo) {
            $parts[] = "**Repository:** {$this->task->repo}";
        }

        if ($this->task->branch) {
            $parts[] = "**Branch:** {$this->task->branch}";
        }

        if ($this->task->requested_outcome) {
            $parts[] = "**Requested Outcome:** {$this->task->requested_outcome}";
        }

        if (! empty($this->task->constraints)) {
            $parts[] = "\n## Constraints";
            foreach ($this->task->constraints as $constraint) {
                $parts[] = "- {$constraint}";
            }
        }

        if (! empty($this->task->evidence)) {
            $parts[] = "\n## Evidence";
            $parts[] = json_encode($this->task->evidence, JSON_PRETTY_PRINT);
        }

        $artifacts = $this->task->artifacts;
        if ($artifacts->isNotEmpty()) {
            $parts[] = "\n## Artifacts";
            foreach ($artifacts as $artifact) {
                $parts[] = "### {$artifact->type->value}";
                $parts[] = $artifact->content;
            }
        }

        $parts[] = "\n## Instructions";
        $parts[] = 'Search your memory for similar past problems. Then evaluate the problem and '
            .'return a structured recommendation. Prefer concrete, actionable plans.';

        return implode("\n", $parts);
    }
}
