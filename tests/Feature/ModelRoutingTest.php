<?php

namespace Tests\Feature;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Ai\Agents\PromptRefinementAgent;
use App\Ai\Prompting\AgentProfileResolver;
use App\Enums\ProblemType;
use App\Models\AgentProfile;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected AgentProfileResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buddy_agents.routing.enabled' => true,
            'buddy_agents.routing.fast_model' => 'gpt-5.4-mini',
            'buddy_agents.routing.fast_problem_types' => ['configuration', 'other'],
        ]);

        $this->resolver = app(AgentProfileResolver::class);
    }

    public function test_fast_problem_types_route_the_evaluator_to_the_fast_model(): void
    {
        $profile = $this->resolver->resolve(EvaluatorOptimizerAgent::AGENT_KEY, ProblemType::Configuration);

        $this->assertSame('gpt-5.4-mini', $profile['model']);
    }

    public function test_full_problem_types_keep_the_base_model(): void
    {
        $profile = $this->resolver->resolve(EvaluatorOptimizerAgent::AGENT_KEY, ProblemType::Security);

        $this->assertSame(config('buddy_agents.profiles.evaluator-optimizer.model'), $profile['model']);
    }

    public function test_refiner_agent_is_never_routed(): void
    {
        $profile = $this->resolver->resolve(PromptRefinementAgent::AGENT_KEY, ProblemType::Configuration);

        $this->assertSame(config('buddy_agents.profiles.prompt-refiner.model'), $profile['model']);
    }

    public function test_routing_kill_switch_returns_the_base_model(): void
    {
        config(['buddy_agents.routing.enabled' => false]);

        $profile = $this->resolver->resolve(EvaluatorOptimizerAgent::AGENT_KEY, ProblemType::Configuration);

        $this->assertSame(config('buddy_agents.profiles.evaluator-optimizer.model'), $profile['model']);
    }

    public function test_active_db_profile_suppresses_routing(): void
    {
        AgentProfile::create([
            'name' => EvaluatorOptimizerAgent::AGENT_KEY,
            'version' => 1,
            'active' => true,
            'provider' => 'openai',
            'model' => 'gpt-5.4-pinned',
            'timeout' => 120,
            'max_steps' => 10,
            'temperature' => 0.2,
        ]);

        $profile = $this->resolver->resolve(EvaluatorOptimizerAgent::AGENT_KEY, ProblemType::Configuration);

        $this->assertSame('gpt-5.4-pinned', $profile['model']);
    }

    public function test_evaluation_run_records_the_routed_model(): void
    {
        EvaluatorOptimizerAgent::fake([
            [
                'accepted' => true,
                'confidence' => 'high',
                'summary' => 'Config value rename.',
                'recommended_plan' => ['Rename the key'],
                'rejected_reasons' => [],
                'required_followups' => [],
                'risks' => [],
                'next_actions' => ['Apply'],
                'memory_hits' => [],
            ],
        ]);

        $task = BuddyTask::factory()->create(['problem_type' => 'configuration']);

        $this->postJson("/api/buddy/tasks/{$task->ulid}/evaluate")->assertOk();

        $run = BuddyRun::query()->where('buddy_task_id', $task->id)->latest('id')->first();

        $this->assertSame('gpt-5.4-mini', $run->model_used);
    }
}
