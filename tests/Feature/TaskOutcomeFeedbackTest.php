<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Enums\TaskOutcome;
use App\Models\ApiClient;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Models\TaskFeedback;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskOutcomeFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected string $key;

    protected ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'buddy.api.auth_required' => true,
            'buddy.langsmith.tracing' => true,
            'buddy.langsmith.api_key' => 'ls-test-key',
            'buddy.langsmith.endpoint' => 'https://langsmith.test',
        ]);

        Http::fake(['langsmith.test/*' => Http::response([], 200)]);

        $this->client = ApiClient::create(['name' => 'feedback-test', 'project' => 'buddy']);
        $this->key = app(ApiKeyService::class)
            ->issue($this->client, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
    }

    protected function closeViaMcp(BuddyTask $task, array $extra = []): void
    {
        $this->withToken($this->key)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'buddy.close_task',
                'arguments' => array_merge(['task_id' => $task->ulid], $extra),
            ],
        ])->assertOk();
    }

    protected function makeTask(array $attributes = []): BuddyTask
    {
        return BuddyTask::factory()->create(array_merge([
            'api_client_id' => $this->client->id,
        ], $attributes));
    }

    public function test_close_with_outcome_persists_feedback_and_posts_to_langsmith(): void
    {
        $task = $this->makeTask();
        BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => 1,
            'run_type' => 'evaluation',
            'status' => 'completed',
            'langsmith_run_id' => 'ls-root-123',
        ]);

        $this->closeViaMcp($task, ['outcome' => 'resolved', 'notes' => 'fix worked first try']);

        $feedback = TaskFeedback::query()->where('buddy_task_id', $task->id)->first();
        $this->assertNotNull($feedback);
        $this->assertSame('resolved', $feedback->outcome);
        $this->assertSame(100, $feedback->score);
        $this->assertSame('agent_close', $feedback->source);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/feedback')
                && $request['run_id'] === 'ls-root-123'
                && $request['key'] === 'task_outcome'
                && (float) $request['score'] === 1.0
                && str_contains($request['comment'], 'resolved');
        });
    }

    public function test_close_without_outcome_stores_nothing_and_posts_nothing(): void
    {
        $task = $this->makeTask();

        $this->closeViaMcp($task);

        $this->assertSame(0, TaskFeedback::query()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/feedback'));
    }

    public function test_abandoned_outcome_stores_null_score_and_sends_comment_only(): void
    {
        $task = $this->makeTask();
        BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => 1,
            'run_type' => 'refinement',
            'status' => 'completed',
            'langsmith_run_id' => 'ls-root-456',
        ]);

        $this->closeViaMcp($task, ['outcome' => 'abandoned', 'notes' => 'priorities changed']);

        $feedback = TaskFeedback::query()->where('buddy_task_id', $task->id)->first();
        $this->assertNotNull($feedback);
        $this->assertNull($feedback->score);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/feedback')
                && $request['run_id'] === 'ls-root-456'
                && ! array_key_exists('score', $request->data());
        });
    }

    public function test_close_with_outcome_but_no_traced_runs_persists_without_posting(): void
    {
        $task = $this->makeTask();

        $this->closeViaMcp($task, ['outcome' => 'not_useful']);

        $this->assertSame(1, TaskFeedback::query()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/feedback'));
    }

    public function test_unknown_outcome_degrades_to_no_feedback(): void
    {
        $task = $this->makeTask();

        $this->closeViaMcp($task, ['outcome' => 'something_else']);

        $this->assertSame(0, TaskFeedback::query()->count());
        $this->assertSame('closed', $task->refresh()->status->value);
    }

    public function test_outcome_enum_scores(): void
    {
        $this->assertSame(100, TaskOutcome::Resolved->score());
        $this->assertSame(50, TaskOutcome::PartiallyResolved->score());
        $this->assertSame(0, TaskOutcome::NotUseful->score());
        $this->assertNull(TaskOutcome::Abandoned->score());
    }
}
