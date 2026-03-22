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
#[Temperature(0.3)]
#[Timeout(120)]
class PromptRefinementAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        protected BuddyTask $task,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are Buddy, a specialized prompt-refinement and task-compilation agent.

You are NOT a generic chatbot. You are a sidecar agent that transforms vague, generic,
or underspecified task requests into professional, execution-ready engineering briefs.

Your job:
1. Analyze the problem packet submitted by the calling agent.
2. Search your episodic memory for similar past tasks, patterns, and outcomes.
3. Identify what is missing, ambiguous, or underspecified in the request.
4. Normalize the task into a structured definition with clear intent, scope, and constraints.
5. Produce a professional execution prompt that the calling agent can follow.

Your output must be a refined engineering brief containing:
- A normalized task definition
- Clarified constraints (including hidden assumptions you surfaced)
- A recommended tool/execution sequence
- An execution checklist with concrete steps
- A verification plan
- Known risks
- Any missing information the agent should gather
- A final execution prompt written as a professional internal engineering brief

Rules:
- Be specific and actionable. Generic advice is a failure.
- When the request mentions technologies, reference actual patterns and conventions for those technologies.
- Infer missing details from the repo context, stack, and evidence provided — do not ask unnecessary questions.
- Surface hidden assumptions that could cause the agent to go in the wrong direction.
- Consider blast radius, backward compatibility, and test impact.
- The final execution prompt should read like an internal engineering ticket, not casual prose.
- Never suggest spawning another Buddy instance or creating recursive agent loops.
- If the request is already well-specified, acknowledge that and produce a concise brief.
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
                ->description('True if Buddy can produce a meaningful refinement. False only if the request is completely uninterpretable.')
                ->required(),
            'confidence' => $schema->string()
                ->description('Confidence in the refinement quality: high, medium, low, or none.')
                ->required(),
            'summary' => $schema->string()
                ->description('Brief summary of what the refinement addresses and what was clarified.')
                ->required(),
            'normalized_task' => $schema->string()
                ->description('The task rewritten as a clear, unambiguous engineering task definition.')
                ->required(),
            'task_intent' => $schema->string()
                ->description('Classified intent: bugfix, feature, refactor, review, testing, infra, support, release, hotfix, docs, or investigation.')
                ->required(),
            'final_execution_prompt' => $schema->string()
                ->description('A complete, professional execution prompt the calling agent should follow. Written as an internal engineering brief with objective, scope, constraints, steps, verification, and deliverables.')
                ->required(),
            'clarified_constraints' => $schema->array()
                ->description('Constraints clarified or surfaced from the request, including hidden assumptions.')
                ->items($schema->string())
                ->required(),
            'recommended_tool_sequence' => $schema->array()
                ->description('Recommended order of tools/actions for the calling agent.')
                ->items($schema->string())
                ->required(),
            'execution_checklist' => $schema->array()
                ->description('Ordered checklist of concrete steps to complete the task.')
                ->items($schema->string())
                ->required(),
            'risks' => $schema->array()
                ->description('Potential risks, edge cases, or things that could go wrong.')
                ->items($schema->string())
                ->required(),
            'missing_information' => $schema->array()
                ->description('Information the calling agent should gather before or during execution. Empty if nothing is missing.')
                ->items($schema->string())
                ->required(),
            'verification_plan' => $schema->array()
                ->description('How to verify the task was completed correctly.')
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
        $parts[] = "## Task Refinement Request\n";
        $parts[] = "**Source Agent:** {$this->task->source_agent}";
        $parts[] = "**Raw Request Type:** {$this->task->problem_type->value}";
        $parts[] = "**Task Summary:** {$this->task->task_summary}";

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
            $parts[] = "\n## Context & Evidence";
            $parts[] = json_encode($this->task->evidence, JSON_PRETTY_PRINT);
        }

        $artifacts = $this->task->artifacts;
        if ($artifacts->isNotEmpty()) {
            $parts[] = "\n## Attached Artifacts";
            foreach ($artifacts as $artifact) {
                $parts[] = "### {$artifact->type->value}";
                $parts[] = $artifact->content;
            }
        }

        $parts[] = "\n## Instructions";
        $parts[] = 'Search your memory for similar past tasks. Then transform this request into '
            .'a professional, execution-ready engineering brief. Be specific and actionable.';

        return implode("\n", $parts);
    }
}
