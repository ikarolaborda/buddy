<?php

namespace App\Ai\Agents;

use App\Ai\Prompting\AgentProfileResolver;
use App\Ai\Prompting\ContextEnvelope;
use App\Ai\Prompting\PromptBundle;
use App\Ai\Prompting\PromptCompiler;
use App\Ai\Tools\SearchMemoryTool;
use App\Models\BuddyTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
class PromptRefinementAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public const AGENT_KEY = 'prompt-refiner';

    protected ?PromptBundle $bundle = null;

    public function __construct(
        protected BuddyTask $task,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->promptBundle()->text;
    }

    public function promptBundle(): PromptBundle
    {
        return $this->bundle ??= app(PromptCompiler::class)
            ->compile(self::AGENT_KEY, $this->task);
    }

    public function provider(): string
    {
        return app(AgentProfileResolver::class)->resolve(self::AGENT_KEY)['provider'];
    }

    public function model(): string
    {
        return app(AgentProfileResolver::class)->resolve(self::AGENT_KEY)['model'];
    }

    public function timeout(): int
    {
        return app(AgentProfileResolver::class)->resolve(self::AGENT_KEY)['timeout'];
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
                ->description('Summaries of relevant past episodes found in memory, including memory IDs.')
                ->items($schema->string())
                ->required(),
        ];
    }

    public function buildPrompt(): string
    {
        return app(ContextEnvelope::class)->forTask(
            $this->task,
            'Task Refinement Request',
            'Search your memory for similar past tasks. Then transform this request into '
            .'a professional, execution-ready engineering brief. Be specific and actionable.',
        );
    }
}
