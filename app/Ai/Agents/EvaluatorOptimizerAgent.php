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
class EvaluatorOptimizerAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public const AGENT_KEY = 'evaluator-optimizer';

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

    public function withBundle(PromptBundle $bundle): self
    {
        $this->bundle = $bundle;

        return $this;
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
                ->description('Summaries of relevant past episodes found in memory, including memory IDs.')
                ->items($schema->string())
                ->required(),
        ];
    }

    public function buildPrompt(): string
    {
        return app(ContextEnvelope::class)->forTask(
            $this->task,
            'Problem Packet',
            'Search your memory for similar past problems. Then evaluate the problem and '
            .'return a structured recommendation. Prefer concrete, actionable plans.',
        );
    }
}
