<?php

namespace Tests\Feature;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\Ai\Agents\PromptRefinementAgent;
use App\Models\BuddyTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuddyTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_task(): void
    {
        $response = $this->postJson('/api/buddy/tasks', [
            'source_agent' => 'claude',
            'task_summary' => 'Login page returns 500 after OAuth callback',
            'problem_type' => 'bug',
            'repo' => 'acme/webapp',
            'branch' => 'feature/oauth-fix',
            'constraints' => ['preserve backward compatibility'],
            'evidence' => [
                ['type' => 'error_log', 'content' => 'NullPointerException at AuthController:42'],
            ],
            'requested_outcome' => 'Fix the 500 error on OAuth callback',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'task_id',
                    'source_agent',
                    'task_summary',
                    'problem_type',
                    'status',
                ],
            ])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source_agent', 'claude')
            ->assertJsonPath('data.problem_type', 'bug');

        $this->assertDatabaseHas('buddy_tasks', [
            'source_agent' => 'claude',
            'status' => 'pending',
        ]);
    }

    public function test_create_task_validates_required_fields(): void
    {
        $response = $this->postJson('/api/buddy/tasks', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_agent', 'task_summary', 'problem_type']);
    }

    public function test_create_task_validates_problem_type_enum(): void
    {
        $response = $this->postJson('/api/buddy/tasks', [
            'source_agent' => 'claude',
            'task_summary' => 'Something broke',
            'problem_type' => 'invalid_type',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['problem_type']);
    }

    public function test_can_show_task(): void
    {
        $task = BuddyTask::factory()->create();

        $response = $this->getJson("/api/buddy/tasks/{$task->ulid}");

        $response->assertOk()
            ->assertJsonPath('data.task_id', $task->ulid)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_show_returns_404_for_missing_task(): void
    {
        $response = $this->getJson('/api/buddy/tasks/01NONEXISTENT00000');

        $response->assertNotFound();
    }

    public function test_can_attach_artifact(): void
    {
        $task = BuddyTask::factory()->create();

        $response = $this->postJson("/api/buddy/tasks/{$task->ulid}/artifacts", [
            'type' => 'log',
            'content' => 'ERROR 2026-03-21 Auth failed at line 42',
            'metadata' => ['file' => 'auth.log'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'type', 'created_at']);

        $this->assertDatabaseHas('buddy_artifacts', [
            'buddy_task_id' => $task->id,
            'type' => 'log',
        ]);
    }

    public function test_cannot_attach_artifact_to_closed_task(): void
    {
        $task = BuddyTask::factory()->closed()->create();

        $response = $this->postJson("/api/buddy/tasks/{$task->ulid}/artifacts", [
            'type' => 'log',
            'content' => 'some log',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_evaluate_closed_task(): void
    {
        $task = BuddyTask::factory()->closed()->create();

        $response = $this->postJson("/api/buddy/tasks/{$task->ulid}/evaluate");

        $response->assertStatus(422);
    }

    public function test_can_evaluate_task_with_faked_agent(): void
    {
        // Pass an array (not JSON string) so the fake returns StructuredTextResponse
        EvaluatorOptimizerAgent::fake([
            [
                'accepted' => true,
                'confidence' => 'high',
                'summary' => 'The OAuth callback needs a null check on the user object.',
                'recommended_plan' => ['Add null check at AuthController:42', 'Add test coverage'],
                'rejected_reasons' => [],
                'required_followups' => [],
                'risks' => ['Minimal — isolated change'],
                'next_actions' => ['Apply the fix', 'Run test suite'],
                'memory_hits' => ['Similar OAuth issue resolved 2 weeks ago'],
            ],
        ]);

        $task = BuddyTask::factory()->create([
            'task_summary' => 'OAuth callback returns 500',
            'problem_type' => 'bug',
        ]);

        $response = $this->postJson("/api/buddy/tasks/{$task->ulid}/evaluate");

        $response->assertOk()
            ->assertJsonPath('evaluation.accepted', true)
            ->assertJsonPath('evaluation.confidence', 'high');

        $this->assertDatabaseHas('buddy_runs', [
            'buddy_task_id' => $task->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('buddy_recommendations', [
            'accepted' => true,
            'confidence' => 'high',
        ]);

        $this->assertDatabaseHas('buddy_decision_logs', [
            'buddy_task_id' => $task->id,
            'decision_type' => 'recommendation_accepted',
        ]);
    }

    public function test_can_close_task(): void
    {
        $task = BuddyTask::factory()->completed()->create();

        $response = $this->postJson("/api/buddy/tasks/{$task->ulid}/close", [
            'learnings_summary' => 'OAuth callback must validate user object before redirect.',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'closed');

        $this->assertDatabaseHas('buddy_tasks', [
            'id' => $task->id,
            'status' => 'closed',
        ]);
    }

    public function test_create_task_with_inline_artifacts(): void
    {
        $response = $this->postJson('/api/buddy/tasks', [
            'source_agent' => 'cursor',
            'task_summary' => 'Test keeps failing',
            'problem_type' => 'test_failure',
            'artifacts' => [
                [
                    'type' => 'test_output',
                    'content' => 'FAIL: test_login_redirect expected 200, got 500',
                ],
            ],
        ]);

        $response->assertStatus(201);

        $taskId = $response->json('data.task_id');
        $task = BuddyTask::where('ulid', $taskId)->first();

        $this->assertNotNull($task);
        $this->assertEquals(1, $task->artifacts()->count());
    }

    public function test_can_refine_prompt_with_faked_agent(): void
    {
        PromptRefinementAgent::fake([
            [
                'accepted' => true,
                'confidence' => 'high',
                'summary' => 'Refined a vague refactoring request into a structured execution brief.',
                'normalized_task' => 'Refactor the UserService to extract email validation into a dedicated FormRequest.',
                'task_intent' => 'refactor',
                'final_execution_prompt' => "## Objective\nRefactor UserService email validation.\n\n## Steps\n1. Create UpdateProfileRequest FormRequest\n2. Move validation rules from service to request\n3. Update controller to use form request\n4. Update tests\n\n## Verification\n- All existing tests pass\n- New form request test covers email validation",
                'clarified_constraints' => ['Preserve backward compatibility', 'No schema changes'],
                'recommended_tool_sequence' => ['Read UserService', 'Create FormRequest', 'Update controller', 'Run tests'],
                'execution_checklist' => ['Create UpdateProfileRequest', 'Move rules', 'Inject in controller', 'Add test', 'Run suite'],
                'risks' => ['Other services may depend on UserService validation directly'],
                'missing_information' => [],
                'verification_plan' => ['Run php artisan test', 'Verify POST /api/users still validates email'],
                'memory_hits' => [],
            ],
        ]);

        $task = BuddyTask::factory()->create([
            'task_summary' => 'help me refactor the user service validation',
            'problem_type' => 'prompt_refinement',
        ]);

        $response = $this->postJson("/api/buddy/tasks/{$task->ulid}/refine");

        $response->assertOk()
            ->assertJsonPath('refinement.accepted', true)
            ->assertJsonPath('refinement.confidence', 'high')
            ->assertJsonPath('refinement.task_intent', 'refactor')
            ->assertJsonStructure([
                'refinement' => [
                    'accepted',
                    'confidence',
                    'summary',
                    'normalized_task',
                    'task_intent',
                    'final_execution_prompt',
                    'clarified_constraints',
                    'recommended_tool_sequence',
                    'execution_checklist',
                    'risks',
                    'missing_information',
                    'verification_plan',
                    'memory_hits',
                ],
            ]);

        $this->assertDatabaseHas('buddy_tasks', [
            'id' => $task->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('buddy_runs', [
            'buddy_task_id' => $task->id,
            'status' => 'completed',
        ]);
    }

    public function test_cannot_refine_closed_task(): void
    {
        $task = BuddyTask::factory()->closed()->create();

        $response = $this->postJson("/api/buddy/tasks/{$task->ulid}/refine");

        $response->assertStatus(422);
    }

    public function test_can_create_prompt_refinement_task(): void
    {
        $response = $this->postJson('/api/buddy/tasks', [
            'source_agent' => 'claude-code',
            'task_summary' => 'make this production ready',
            'problem_type' => 'prompt_refinement',
            'repo' => 'acme/api',
            'constraints' => ['no new dependencies'],
            'evidence' => [
                ['type' => 'context', 'content' => 'Stack: Laravel 13, PHP 8.5, SQLite dev / PG prod'],
            ],
            'requested_outcome' => 'Return a professional execution prompt with steps, risks, and verification plan',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.problem_type', 'prompt_refinement')
            ->assertJsonPath('data.status', 'pending');
    }
}
