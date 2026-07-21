<?php

namespace Tests\Unit;

use App\Ai\Prompting\PromptCompiler;
use App\Enums\ProblemType;
use App\Models\BuddyTask;
use Tests\TestCase;

class PromptCompilerTest extends TestCase
{
    protected PromptCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = app(PromptCompiler::class);
    }

    protected function makeTask(ProblemType $type): BuddyTask
    {
        $task = new BuddyTask;
        $task->problem_type = $type;

        return $task;
    }

    public function test_it_always_includes_core_modules_and_agent_overlay(): void
    {
        $bundle = $this->compiler->compile('evaluator-optimizer', $this->makeTask(ProblemType::Bug));

        $this->assertContains('core/identity', $bundle->moduleIds);
        $this->assertContains('core/security-boundaries', $bundle->moduleIds);
        $this->assertContains('core/memory-policy', $bundle->moduleIds);
        $this->assertContains('agents/evaluator-optimizer', $bundle->moduleIds);
        $this->assertStringContainsString('You are Buddy', $bundle->text);
    }

    public function test_it_routes_security_tasks_to_the_cybersecurity_module(): void
    {
        $bundle = $this->compiler->compile('evaluator-optimizer', $this->makeTask(ProblemType::Security));

        $this->assertContains('domains/cybersecurity', $bundle->moduleIds);
    }

    public function test_it_routes_performance_tasks_to_the_performance_module(): void
    {
        $bundle = $this->compiler->compile('evaluator-optimizer', $this->makeTask(ProblemType::Performance));

        $this->assertContains('domains/performance-and-memory', $bundle->moduleIds);
        $this->assertNotContains('domains/cybersecurity', $bundle->moduleIds);
    }

    public function test_compilation_is_deterministic(): void
    {
        $first = $this->compiler->compile('evaluator-optimizer', $this->makeTask(ProblemType::Bug));
        $second = $this->compiler->compile('evaluator-optimizer', $this->makeTask(ProblemType::Bug));

        $this->assertSame($first->contentHash, $second->contentHash);
        $this->assertSame(64, strlen($first->contentHash));
    }

    public function test_different_agents_produce_different_bundles(): void
    {
        $evaluator = $this->compiler->compile('evaluator-optimizer', $this->makeTask(ProblemType::Bug));
        $refiner = $this->compiler->compile('prompt-refiner', $this->makeTask(ProblemType::Bug));

        $this->assertNotSame($evaluator->contentHash, $refiner->contentHash);
    }

    public function test_every_module_hash_is_recorded(): void
    {
        $bundle = $this->compiler->compile('prompt-refiner', $this->makeTask(ProblemType::PromptRefinement));

        $this->assertSame($bundle->moduleIds, array_keys($bundle->moduleHashes));

        foreach ($bundle->moduleHashes as $hash) {
            $this->assertSame(64, strlen($hash));
        }
    }
}
