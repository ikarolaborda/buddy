<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\BuddyIntervention;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Services\ApiKeyService;
use App\Services\Edge\EdgeDelegationService;
use App\Services\Edge\TaskProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EdgeSupervisionTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $owner;

    private ApiClient $other;

    private BuddyTask $task;

    private BuddyTaskEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        config([
            'buddy.api.auth_required' => true,
            'buddy.edge.progress' => true,
            'buddy.edge.supervision' => true,
            'buddy.edge.service_key' => 'edge-service-key',
        ]);
        $service = app(ApiKeyService::class);
        $this->owner = ApiClient::create(['name' => 'owner', 'project' => 'buddy']);
        $this->other = ApiClient::create(['name' => 'other', 'project' => 'buddy']);
        $service->issue($this->owner, [ApiScope::TasksRead, ApiScope::InterventionsExecute]);
        $service->issue($this->other, [ApiScope::TasksRead, ApiScope::InterventionsExecute]);
        $this->task = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
        $this->event = app(TaskProgressService::class)->phase($this->task, 'queued');
    }

    private function edge(): static
    {
        return $this->withHeaders(['X-Buddy-Edge-Key' => 'edge-service-key']);
    }

    private function delegate(array $body, ?BuddyTask $task = null): TestResponse
    {
        return $this->edge()->postJson('/api/internal/cloudflare/tasks/'.($task ?? $this->task)->ulid.'/delegations', $body);
    }

    private function proof(): array
    {
        return ['event_id' => $this->event->id, 'client_id' => (string) $this->owner->id];
    }

    private function statusFor(?string $delegation, ?BuddyTask $task = null): TestResponse
    {
        $query = $delegation === null ? '' : '?delegation='.urlencode($delegation);

        return $this->edge()->getJson('/api/internal/cloudflare/tasks/'.($task ?? $this->task)->ulid.'/status'.$query);
    }

    public function test_delegations_require_the_supervision_flag(): void
    {
        config(['buddy.edge.supervision' => false]);
        $this->delegate($this->proof())->assertNotFound();

        config(['buddy.edge.supervision' => true]);
        $this->delegate($this->proof())->assertCreated();
    }

    public function test_the_service_key_alone_mints_nothing(): void
    {
        $this->postJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/delegations', $this->proof())->assertUnauthorized();

        $this->delegate([])->assertNotFound();
        $this->delegate(['event_id' => $this->event->id])->assertNotFound();
        $this->delegate(['client_id' => (string) $this->owner->id])->assertNotFound();
        $this->delegate(['event_id' => '', 'client_id' => (string) $this->owner->id])->assertNotFound();
        $this->delegate(['event_id' => $this->event->id, 'client_id' => ''])->assertNotFound();
        $this->delegate(['event_id' => $this->event->id, 'client_id' => ['1']])->assertNotFound();
    }

    public function test_delegations_require_the_owning_client(): void
    {
        $this->delegate(['client_id' => (string) $this->other->id] + $this->proof())->assertNotFound();
        $this->delegate(['client_id' => $this->owner->id.'1'] + $this->proof())->assertNotFound();
        $this->delegate(['client_id' => 'owner'] + $this->proof())->assertNotFound();

        $this->delegate(['client_id' => $this->owner->id] + $this->proof())->assertCreated();
    }

    public function test_delegations_require_a_recently_delivered_event_of_this_task(): void
    {
        $sibling = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
        $siblingEvent = app(TaskProgressService::class)->phase($sibling, 'queued');

        $this->delegate(['event_id' => $siblingEvent->id] + $this->proof())->assertNotFound();
        $this->delegate(['event_id' => (string) Str::ulid()] + $this->proof())->assertNotFound();
        $this->delegate($this->proof(), $sibling)->assertNotFound();

        $this->travel(47)->hours();
        $this->delegate($this->proof())->assertCreated();

        $this->travel(2)->hours();
        $this->delegate($this->proof())->assertNotFound();
    }

    public function test_delegations_require_a_client_currently_holding_interventions_execute(): void
    {
        $viewer = ApiClient::create(['name' => 'viewer', 'project' => 'buddy']);
        app(ApiKeyService::class)->issue($viewer, [ApiScope::TasksRead]);
        $task = BuddyTask::factory()->create(['api_client_id' => $viewer->id]);
        $event = app(TaskProgressService::class)->phase($task, 'queued');

        $this->delegate(['event_id' => $event->id, 'client_id' => (string) $viewer->id], $task)->assertNotFound();

        app(ApiKeyService::class)->issue($viewer, [ApiScope::InterventionsExecute]);
        $this->delegate(['event_id' => $event->id, 'client_id' => (string) $viewer->id], $task)->assertCreated();

        $viewer->update(['active' => false]);
        $this->delegate(['event_id' => $event->id, 'client_id' => (string) $viewer->id], $task)->assertNotFound();
    }

    public function test_refusals_are_indistinguishable(): void
    {
        // Debug rendering appends stack traces, which would differ by line
        // number; the contract under test is the production response body.
        config(['app.debug' => false]);
        $wrongClient = $this->delegate(['client_id' => (string) $this->other->id] + $this->proof())->assertNotFound();
        $unknownEvent = $this->delegate(['event_id' => (string) Str::ulid()] + $this->proof())->assertNotFound();
        config(['buddy.edge.supervision' => false]);
        $flagOff = $this->delegate($this->proof())->assertNotFound();

        $this->assertSame($wrongClient->getContent(), $unknownEvent->getContent());
        $this->assertSame($wrongClient->getContent(), $flagOff->getContent());
    }

    public function test_a_minted_delegation_verifies_for_the_task_only(): void
    {
        $response = $this->delegate($this->proof())
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['delegation', 'expires_at', 'generation'])
            ->assertJsonPath('generation', 1);

        $token = $response->json('delegation');
        $this->assertStringStartsWith(EdgeDelegationService::PREFIX, $token);
        $this->assertTrue(Carbon::parse($response->json('expires_at'))->isAfter(now()->addDay()));

        $delegations = app(EdgeDelegationService::class);
        $this->assertSame($this->task->ulid, $delegations->verify($token, $this->task, ApiScope::InterventionsExecute)['task']);
        $this->assertSame([ApiScope::InterventionsExecute->value], $delegations->verify($token, $this->task, ApiScope::InterventionsExecute)['scopes']);
        $this->assertNull($delegations->verify($token, $this->task, ApiScope::TasksRead));

        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->other->id]);
        $this->assertNull($delegations->verify($token, $foreign, ApiScope::InterventionsExecute));
    }

    public function test_status_answers_only_a_valid_delegation_with_lifecycle_facts(): void
    {
        $token = $this->delegate($this->proof())->json('delegation');

        $this->statusFor(null)->assertNotFound();
        $this->statusFor('')->assertNotFound();
        $this->statusFor('bdg1.forged.sig')->assertNotFound();
        $this->statusFor(substr_replace($token, 'AAAA', 10, 4))->assertNotFound();

        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
        $this->statusFor($token, $foreign)->assertNotFound();

        $response = $this->statusFor($token)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('task_id', $this->task->ulid)
            ->assertJsonPath('operation', 'evaluate')
            ->assertJsonPath('is_recovery', false)
            ->assertJsonPath('progress.status', 'pending')
            ->assertJsonPath('progress.phase', 'queued')
            ->assertJsonPath('progress.progress_sequence', 1)
            ->assertJsonPath('progress.generation', 1)
            ->assertJsonPath('progress.recovery_task_id', null);

        $this->assertSame(['task_id', 'operation', 'is_recovery', 'progress'], array_keys($response->json()));
        $this->assertStringNotContainsString($this->task->task_summary, $response->getContent());
        $this->assertStringNotContainsString('edge-service-key', $response->getContent());

        config(['buddy.edge.supervision' => false]);
        $this->statusFor($token)->assertNotFound();
    }

    public function test_status_marks_recovery_children_and_links_them_from_the_original(): void
    {
        $original = BuddyTask::factory()->failed()->create(['api_client_id' => $this->owner->id]);
        $originalEvent = app(TaskProgressService::class)->phase($original, 'terminal');
        $recovery = BuddyTask::factory()->create(['api_client_id' => $this->owner->id, 'recovery_of_task_id' => $original->id]);
        $recoveryEvent = app(TaskProgressService::class)->phase($recovery, 'queued');

        $originalToken = $this->delegate(['event_id' => $originalEvent->id, 'client_id' => (string) $this->owner->id], $original)->json('delegation');
        $recoveryToken = $this->delegate(['event_id' => $recoveryEvent->id, 'client_id' => (string) $this->owner->id], $recovery)->json('delegation');

        $this->statusFor($originalToken, $original)->assertOk()
            ->assertJsonPath('is_recovery', false)
            ->assertJsonPath('progress.recovery_task_id', $recovery->ulid);
        $this->statusFor($recoveryToken, $recovery)->assertOk()
            ->assertJsonPath('is_recovery', true)
            ->assertJsonPath('progress.recovery_task_id', null);
        $this->statusFor($originalToken, $recovery)->assertNotFound();
    }

    public function test_revocation_and_generation_changes_fail_closed(): void
    {
        $token = $this->delegate($this->proof())->json('delegation');
        $this->statusFor($token)->assertOk();

        BuddyTask::query()->whereKey($this->task->id)->update(['generation' => 2]);
        $this->statusFor($token)->assertNotFound();
        BuddyTask::query()->whereKey($this->task->id)->update(['generation' => 1]);
        $this->statusFor($token)->assertOk();

        app(ApiKeyService::class)->revoke($this->owner->apiKeys()->sole());
        $this->statusFor($token)->assertNotFound();
        $this->delegate($this->proof())->assertNotFound();
        $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/interventions', [
            'delegation' => $token,
            'request_id' => 'sup-revoked',
            'action' => 'diagnose_health',
            'blocker' => 'operational_failure',
            'context' => ['summary' => 'Supervisor probe after revocation.'],
        ])->assertNotFound();
        $this->assertSame(0, BuddyIntervention::count());
    }

    public function test_a_supervisor_can_diagnose_health_with_a_delegation_it_minted(): void
    {
        $token = $this->delegate($this->proof())->json('delegation');

        $response = $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/interventions', [
            'delegation' => $token,
            'request_id' => 'sup-health-1',
            'action' => 'diagnose_health',
            'blocker' => 'operational_failure',
            'context' => ['summary' => 'Supervisor observed a long queue wait.'],
        ])
            ->assertOk()
            ->assertJsonPath('action', 'diagnose_health')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.observations.observation_state', 'healthy')
            ->assertJsonPath('result.observations.evaluating_count', 0);

        $this->assertStringStartsWith('delegation:', BuddyIntervention::sole()->context['principal']);
        $this->assertSame($response->json('intervention_id'), $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/interventions', [
            'delegation' => $token,
            'request_id' => 'sup-health-1',
            'action' => 'diagnose_health',
            'blocker' => 'operational_failure',
            'context' => ['summary' => 'Supervisor observed a long queue wait.'],
        ])->assertOk()->json('intervention_id'));
        Http::assertNothingSent();
    }
}
