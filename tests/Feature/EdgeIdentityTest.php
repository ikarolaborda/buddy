<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\BuddyIntervention;
use App\Models\BuddyTask;
use App\Models\BuddyViewSession;
use App\Services\ApiKeyService;
use App\Services\Edge\EdgeDelegationService;
use App\Services\Edge\TaskProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EdgeIdentityTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $owner;

    private ApiClient $other;

    private string $ownerKey;

    private string $otherKey;

    private BuddyTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        config([
            'buddy.api.auth_required' => true,
            'buddy.edge.progress' => true,
            'buddy.edge.service_key' => 'edge-service-key',
        ]);
        $service = app(ApiKeyService::class);
        $this->owner = ApiClient::create(['name' => 'owner', 'project' => 'buddy']);
        $this->other = ApiClient::create(['name' => 'other', 'project' => 'buddy']);
        $this->ownerKey = $service->issue($this->owner, [ApiScope::TasksRead, ApiScope::InterventionsExecute])['plaintext'];
        $this->otherKey = $service->issue($this->other, [ApiScope::TasksRead, ApiScope::InterventionsExecute])['plaintext'];
        $this->task = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
    }

    private function edge(): static
    {
        return $this->withHeaders(['X-Buddy-Edge-Key' => 'edge-service-key']);
    }

    public function test_view_tickets_require_the_owner_and_the_progress_flag(): void
    {
        $this->withToken($this->otherKey)->postJson('/api/buddy/tasks/'.$this->task->ulid.'/view-tickets')->assertNotFound();

        $this->withToken($this->ownerKey)->postJson('/api/buddy/tasks/'.$this->task->ulid.'/view-tickets')
            ->assertCreated()
            ->assertJsonPath('task_id', $this->task->ulid)
            ->assertJsonPath('scope', 'task:view');

        config(['buddy.edge.progress' => false]);
        $this->withToken($this->ownerKey)->postJson('/api/buddy/tasks/'.$this->task->ulid.'/view-tickets')->assertNotFound();
    }

    public function test_tickets_exchange_exactly_once_and_sessions_are_task_bound(): void
    {
        $ticket = $this->withToken($this->ownerKey)->postJson('/api/buddy/tasks/'.$this->task->ulid.'/view-tickets')->json('ticket');

        $this->postJson('/api/internal/cloudflare/sessions/exchange', ['ticket' => $ticket])->assertUnauthorized();
        $this->withHeaders(['X-Buddy-Edge-Key' => 'wrong'])->postJson('/api/internal/cloudflare/sessions/exchange', ['ticket' => $ticket])->assertUnauthorized();

        $session = $this->edge()->postJson('/api/internal/cloudflare/sessions/exchange', ['ticket' => $ticket])
            ->assertCreated()
            ->assertJsonPath('task_id', $this->task->ulid)
            ->json('session_token');

        $this->edge()->postJson('/api/internal/cloudflare/sessions/exchange', ['ticket' => $ticket])->assertStatus(410);
        $this->assertSame(1, BuddyViewSession::count());
        $this->assertStringNotContainsString($session, json_encode(BuddyViewSession::first()->toArray()));

        app(TaskProgressService::class)->phase($this->task, 'queued');

        $this->edge()->getJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/snapshot')->assertUnauthorized();
        $this->edge()->withHeaders(['X-Buddy-Edge-Session' => $session])
            ->getJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/snapshot')
            ->assertOk()
            ->assertJsonPath('progress.progress_sequence', 1)
            ->assertJsonPath('events.0.task_sequence', 1)
            ->assertJsonPath('events.0.type', 'buddy.task.progress.v1');
        $this->edge()->withHeaders(['X-Buddy-Edge-Session' => $session])
            ->getJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/snapshot?after_sequence=1')
            ->assertOk()
            ->assertJsonCount(0, 'events');

        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->other->id]);
        $this->edge()->withHeaders(['X-Buddy-Edge-Session' => $session])
            ->getJson('/api/internal/cloudflare/tasks/'.$foreign->ulid.'/snapshot')
            ->assertUnauthorized();

        $this->owner->update(['active' => false]);
        $this->edge()->withHeaders(['X-Buddy-Edge-Session' => $session])
            ->getJson('/api/internal/cloudflare/tasks/'.$this->task->ulid.'/snapshot')
            ->assertUnauthorized();
    }

    public function test_expired_tickets_cannot_be_exchanged(): void
    {
        $ticket = $this->withToken($this->ownerKey)->postJson('/api/buddy/tasks/'.$this->task->ulid.'/view-tickets')->json('ticket');
        $this->travel(61)->seconds();

        $this->edge()->postJson('/api/internal/cloudflare/sessions/exchange', ['ticket' => $ticket])->assertStatus(410);
    }

    public function test_origin_policy_is_enforced_when_configured(): void
    {
        config(['buddy.edge.allowed_origins' => ['https://buddy-edge-preview.workers.dev']]);
        $ticket = $this->withToken($this->ownerKey)->postJson('/api/buddy/tasks/'.$this->task->ulid.'/view-tickets')->json('ticket');

        $this->edge()->postJson('/api/internal/cloudflare/sessions/exchange', ['ticket' => $ticket, 'origin' => 'https://evil.example'])->assertStatus(410);
        $ticket = $this->withToken($this->ownerKey)->postJson('/api/buddy/tasks/'.$this->task->ulid.'/view-tickets')->json('ticket');
        $this->edge()->postJson('/api/internal/cloudflare/sessions/exchange', ['ticket' => $ticket, 'origin' => 'https://buddy-edge-preview.workers.dev'])->assertCreated();
    }

    public function test_delegations_bind_client_task_generation_scope_and_current_revocation(): void
    {
        $delegations = app(EdgeDelegationService::class);
        $token = $delegations->mint($this->task, [ApiScope::InterventionsExecute]);

        $this->assertNotNull($delegations->verify($token, $this->task, ApiScope::InterventionsExecute));
        $this->assertNull($delegations->verify($token, $this->task, ApiScope::TasksWrite));
        $this->assertNull($delegations->verify($token.'x', $this->task, ApiScope::InterventionsExecute));
        $this->assertNull($delegations->verify(substr_replace($token, 'AAAA', 10, 4), $this->task, ApiScope::InterventionsExecute));

        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->other->id]);
        $this->assertNull($delegations->verify($token, $foreign, ApiScope::InterventionsExecute));

        BuddyTask::query()->whereKey($this->task->id)->update(['generation' => 2]);
        $this->assertNull($delegations->verify($token, $this->task->fresh(), ApiScope::InterventionsExecute));
        BuddyTask::query()->whereKey($this->task->id)->update(['generation' => 1]);

        $this->travel(3)->days();
        $this->assertNull($delegations->verify($token, $this->task->fresh(), ApiScope::InterventionsExecute));
        $this->travelBack();

        $key = $this->owner->apiKeys()->first();
        app(ApiKeyService::class)->revoke($key);
        $this->assertNull($delegations->verify($token, $this->task->fresh(), ApiScope::InterventionsExecute));
    }

    public function test_delegated_interventions_respect_flags_and_recovery_limits(): void
    {
        $task = BuddyTask::factory()->failed()->create(['api_client_id' => $this->owner->id]);
        $task->runs()->create(['run_number' => 1, 'run_type' => 'evaluation', 'status' => 'failed', 'error_category' => 'transient']);
        $token = app(EdgeDelegationService::class)->mint($task, [ApiScope::InterventionsExecute]);
        $packet = ['delegation' => $token, 'request_id' => 'sup-1', 'action' => 'recover_evaluation', 'blocker' => 'operational_failure', 'context' => ['summary' => 'Supervisor observed a transient failure.']];

        $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$task->ulid.'/interventions', $packet)->assertNotFound();

        config(['buddy.edge.supervision' => true]);
        $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$task->ulid.'/interventions', $packet)->assertStatus(422)->assertJsonPath('error', 'auto_recovery_disabled');

        $diagnose = ['action' => 'diagnose_health', 'request_id' => 'sup-health-1'] + $packet;
        $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$task->ulid.'/interventions', $diagnose)->assertOk()->assertJsonPath('action', 'diagnose_health');

        config(['buddy.edge.auto_recovery' => true]);
        $first = $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$task->ulid.'/interventions', $packet)->assertOk()->assertJsonPath('status', 'dispatched');
        $second = $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$task->ulid.'/interventions', ['request_id' => 'sup-2'] + $packet)->assertOk();
        $this->assertSame($first->json('result.recovery_task_id'), $second->json('result.recovery_task_id'));
        $this->assertSame(1, BuddyTask::where('recovery_of_task_id', $task->id)->count());
        $this->assertStringStartsWith('delegation:', BuddyIntervention::where('request_id', 'sup-1')->sole()->context['principal']);

        $this->edge()->postJson('/api/internal/cloudflare/tasks/'.$task->ulid.'/interventions', ['delegation' => 'bdg1.forged.sig'] + $packet)->assertNotFound();
    }
}
