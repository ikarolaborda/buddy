<?php

namespace App\Ai\Agents;

use App\Ai\Prompting\AgentProfileResolver;
use App\Ai\Prompting\ContextEnvelope;
use App\Ai\Prompting\PromptBundle;
use App\Ai\Prompting\PromptCompiler;
use App\DTOs\MemorySearchPage;
use App\Models\BuddyTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
class EvaluatorOptimizerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const AGENT_KEY = 'evaluator-optimizer';

    protected ?PromptBundle $bundle = null;

    public function __construct(
        protected BuddyTask $task,
        protected ?MemorySearchPage $memoryPage = null,
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
        return $this->profile()['provider'];
    }

    public function model(): string
    {
        return $this->profile()['model'];
    }

    public function timeout(): int
    {
        return $this->profile()['timeout'];
    }

    /**
     * @return array{provider: string, model: string, timeout: int, max_steps: int, temperature: float}
     */
    protected function profile(): array
    {
        return app(AgentProfileResolver::class)
            ->resolve(self::AGENT_KEY, $this->task->problem_type);
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
                ->description('Qdrant memory IDs from the supplied grounding context that informed the result.')
                ->items($schema->string())
                ->required(),
            'knowledge_hits' => $schema->array()
                ->description('Algolia record IDs from the supplied grounding context that informed the result.')
                ->items($schema->string()),
        ];
    }

    public function buildPrompt(): string
    {
        return app(ContextEnvelope::class)->forTask(
            $this->task,
            'Problem Packet',
            'Use the supplied grounding snapshot when relevant; do not perform another memory search. '
            .'Then evaluate the problem and return a structured recommendation. Cite the exact record '
            .'or memory IDs you relied on and prefer concrete, actionable plans.',
            $this->memoryPage,
        );
    }
}
