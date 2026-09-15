<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Jobs\EvaluateTaskJob;
use App\Models\ApiClient;
use App\Models\BuddyIntervention;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use App\Services\Interventions\ServiceDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InterventionTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $client;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['buddy.api.auth_required' => true]);
        $this->client = ApiClient::create(['name' => 'intervention-test', 'project' => 'buddy']);
        $this->key = app(ApiKeyService::class)->issue($this->client, [ApiScope::TasksRead, ApiScope::InterventionsExecute])['plaintext'];
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function task(): BuddyTask
    {
        $task = BuddyTask::factory()->failed()->create(['api_client_id' => $this->client->id]);
        $task->runs()->create(['run_number' => 1, 'run_type' => 'evaluation', 'status' => 'failed', 'error_class' => ConnectionException::class, 'error_category' => 'transient']);

        return $task;
    }

    private function packet(string $action = 'recover_evaluation'): array
    {
        return ['request_id' => 'attempt-1', 'action' => $action, 'blocker' => 'operational_failure', 'context' => ['summary' => 'Evaluation failed after connection timeout.', 'session_id' => 'session-1', 'attempted_actions' => ['Polled task status.']]];
    }

    private function intervene(BuddyTask $task, array $packet, ?string $key = null)
    {
        return $this->withToken($key ?? $this->key)->postJson('/api/buddy/tasks/'.$task->ulid.'/interventions', $packet);
    }

    public function test_recovery_preserves_failed_task_and_context_and_dispatches_only_once(): void
    {
        $task = $this->task();
        $task->artifacts()->create(['type' => 'log', 'content' => 'Connection failed.']);
        $packet = $this->packet();
        $first = $this->intervene($task, $packet)->assertOk()->assertJsonPath('status', 'dispatched');
        $second = $this->intervene($task, $packet)->assertOk();
        $this->assertSame($first->json(), $second->json());
        $reordered = array_reverse($packet, true);
        $reordered['context'] = array_reverse($packet['context'], true);
        $this->assertSame($first->json(), $this->intervene($task, $reordered)->assertOk()->json());
        $recovery = BuddyTask::where('recovery_of_task_id', $task->id)->sole();
        $this->assertSame('failed', $task->refresh()->status->value);
        $this->assertSame('evaluating', $recovery->status->value);
        $this->assertSame($task->evidence, $recovery->evidence);
        $this->assertSame($task->constraints, $recovery->constraints);
        $this->assertSame('Connection failed.', $recovery->artifacts()->where('type', 'log')->sole()->content);
        $this->assertStringContainsString('session-1', $recovery->artifacts()->where('type', 'other')->sole()->content);
        $this->assertSame($recovery->ulid, $first->json('result.recovery_task_id'));
        Queue::assertPushed(EvaluateTaskJob::class, 1);
        $this->assertDatabaseCount('buddy_interventions', 1);
        $this->assertDatabaseHas('outbox_messages', ['message_key' => $recovery->ulid.':evaluate']);

        $packet['request_id'] = 'attempt-2';
        $this->intervene($task, $packet)->assertOk()->assertJsonPath('result.recovery_task_id', $recovery->ulid);
        Queue::assertPushed(EvaluateTaskJob::class, 1);
    }

    public function test_different_payload_on_same_id_is_rejected(): void
    {
        $task = $this->task();
        $packet = $this->packet();
        $this->intervene($task, $packet)->assertOk();
        $packet['context']['summary'] = 'Different action context.';
        $this->intervene($task, $packet)->assertUnprocessable();
        Queue::assertPushed(EvaluateTaskJob::class, 1);
    }

    public function test_scope_and_owner_are_enforced_on_rest_and_mcp(): void
    {
        $task = $this->task();
        $weakKey = app(ApiKeyService::class)->issue($this->client, [ApiScope::TasksWrite])['plaintext'];
        $this->intervene($task, $this->packet(), $weakKey)->assertForbidden();
        $other = ApiClient::create(['name' => 'other', 'project' => 'buddy']);
        $otherTask = BuddyTask::factory()->create(['api_client_id' => $other->id]);
        $this->intervene($otherTask, $this->packet())->assertNotFound();
        foreach ([[$weakKey, $task], [$this->key, $otherTask]] as [$key, $target]) {
            $this->withToken($key)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'buddy.intervene', 'arguments' => $this->packet() + ['task_id' => $target->ulid]]])->assertOk()->assertJsonPath('result.isError', true);
        }
        $this->assertDatabaseCount('buddy_interventions', 0);
        Queue::assertNothingPushed();
    }

    public function test_restrictions_and_permanent_errors_never_trigger_recovery(): void
    {
        foreach (['policy_denied', 'approval_required', 'credential_restriction', 'capability_missing'] as $blocker) {
            $this->intervene($this->task(), array_replace($this->packet(), ['blocker' => $blocker]))->assertOk()->assertJsonPath('status', 'blocked');
        }
        $task = $this->task();
        $task->runs()->update(['error_class' => \RuntimeException::class, 'error_category' => 'permanent']);
        $this->intervene($task, $this->packet())->assertOk()->assertJsonPath('status', 'blocked');
        $this->assertSame(0, BuddyTask::whereNotNull('recovery_of_task_id')->count());
        Queue::assertNothingPushed();
    }

    public function test_councils_live_tasks_and_recovery_chains_are_not_replayed(): void
    {
        foreach (['council', 'evaluating', 'recovery'] as $case) {
            $task = $this->task();
            if ($case === 'council') {
                $task->update(['operation' => 'council']);
            } elseif ($case === 'evaluating') {
                $task->update(['status' => 'evaluating']);
            } else {
                $task->update(['recovery_of_task_id' => $this->task()->id]);
            }
            $this->intervene($task, $this->packet())->assertOk()->assertJsonPath('status', 'blocked');
        }
        Queue::assertNothingPushed();
    }

    public function test_health_diagnosis_is_audited_redacted_and_idempotent(): void
    {
        $this->mock(ServiceDiagnostics::class)->shouldReceive('inspect')->once()->andReturn(['health' => 'ready', 'checks' => ['database' => true]]);
        $task = $this->task();
        $task->artifacts()->create(['type' => 'log', 'content' => "Authorization: Bearer PRIVATE_TOKEN\nCookie: session=PRIVATE_COOKIE\npassword=PRIVATE_PASSWORD"]);
        $packet = $this->packet('diagnose_health');
        $response = $this->intervene($task, $packet)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('result.health', 'ready');
        $this->intervene($task, $packet)->assertOk()->assertJsonPath('intervention_id', $response->json('intervention_id'));
        $stored = (string) json_encode(BuddyIntervention::sole()->context);
        $this->assertStringNotContainsString('PRIVATE_TOKEN', $stored);
        $this->assertStringNotContainsString('PRIVATE_COOKIE', $stored);
        $this->assertStringNotContainsString('PRIVATE_PASSWORD', $stored);
        $this->assertStringContainsString('session-1', $stored);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_unknown_actions_and_unbounded_context_are_rejected(): void
    {
        $task = $this->task();
        $this->intervene($task, array_replace($this->packet(), ['action' => 'shell']))->assertUnprocessable();
        $packet = $this->packet();
        $packet['context']['summary'] = str_repeat('x', 4001);
        $this->intervene($task, $packet)->assertUnprocessable();
        $this->assertDatabaseCount('buddy_interventions', 0);
    }

    public function test_interventions_can_be_disabled_without_dispatch(): void
    {
        config(['buddy.interventions.enabled' => false]);
        $this->intervene($this->task(), $this->packet())->assertUnprocessable();
        $this->assertDatabaseCount('buddy_interventions', 0);
        Queue::assertNothingPushed();
    }
}
