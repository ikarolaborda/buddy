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
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
class EvaluatorOptimizerAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
     * Reasoning effort reaches the Responses API only when the profile sets
     * it and the provider is an OpenAI-family lab; anything else keeps the
     * provider default so an unset value changes nothing.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $effort = $this->profile()['reasoning_effort'] ?? null;
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        if (! in_array($effort, ['low', 'medium', 'high'], true) || ! in_array($lab, [Lab::Azure, Lab::OpenAI], true)) {
            return [];
        }

        return ['reasoning' => ['effort' => $effort]];
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
            // OpenAI strict structured output rejects a schema whose `required` omits any
            // declared property, so this must stay required like its siblings; an empty
            // array is how "no knowledge hits" is expressed.
            'knowledge_hits' => $schema->array()
                ->description('Algolia record IDs from the supplied grounding context that informed the result.')
                ->items($schema->string())
                ->required(),
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
