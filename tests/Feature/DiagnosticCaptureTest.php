<?php

namespace Tests\Feature;

use App\Contracts\BrowserCaptureDispatcher;
use App\Enums\ApiScope;
use App\Jobs\DispatchDiagnosticCaptureJob;
use App\Models\ApiClient;
use App\Models\BuddyArtifact;
use App\Models\BuddyDiagnosticCapture;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Services\ApiKeyService;
use App\Services\Diagnostics\CaptureTargetPolicy;
use App\Services\Edge\TaskProgressService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DiagnosticCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER = 'https://buddy-edge.example.workers.dev';

    private ApiClient $owner;

    private ApiClient $other;

    private string $ownerKey;

    private string $otherKey;

    private BuddyTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'buddy.api.auth_required' => true,
            'buddy.edge.browser_diagnostics' => true,
            'buddy.edge.service_key' => 'edge-service-key',
            'buddy.edge.worker_url' => self::WORKER,
        ]);
        $service = app(ApiKeyService::class);
        $this->owner = ApiClient::create(['name' => 'owner', 'project' => 'buddy']);
        $this->other = ApiClient::create(['name' => 'other', 'project' => 'buddy']);
        $this->ownerKey = $service->issue($this->owner, [ApiScope::TasksRead, ApiScope::DiagnosticsCapture])['plaintext'];
        $this->otherKey = $service->issue($this->other, [ApiScope::TasksRead, ApiScope::DiagnosticsCapture])['plaintext'];
        $this->task = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(array $overrides = []): array
    {
        return array_replace([
            'request_id' => 'capture-1',
            'url' => 'https://example.com/status',
            'purpose' => 'Confirm the status page renders after the deploy.',
            'allowed_hosts' => ['example.com'],
            'allow_subresources_same_host' => true,
            'redirects_allowed' => false,
            'capture_seconds' => 15,
        ], $overrides);
    }

    private function create(array $packet, ?string $key = null, ?BuddyTask $task = null)
    {
        return $this->withToken($key ?? $this->ownerKey)
            ->postJson('/api/buddy/tasks/'.($task ?? $this->task)->ulid.'/diagnostic-captures', $packet);
    }

    private function show(string $captureId, ?string $key = null, ?BuddyTask $task = null)
    {
        return $this->withToken($key ?? $this->ownerKey)
            ->getJson('/api/buddy/tasks/'.($task ?? $this->task)->ulid.'/diagnostic-captures/'.$captureId);
    }

    private function complete(BuddyDiagnosticCapture $capture, array $body, ?BuddyTask $task = null)
    {
        return $this->withHeaders(['X-Buddy-Edge-Key' => 'edge-service-key'])
            ->postJson('/api/internal/cloudflare/tasks/'.($task ?? $this->task)->ulid.'/diagnostic-captures/'.$capture->id.'/complete', $body);
    }

    private function record(array $attributes = []): BuddyDiagnosticCapture
    {
        return BuddyDiagnosticCapture::create($attributes + [
            'buddy_task_id' => $this->task->id,
            'api_client_id' => $this->owner->id,
            'request_id' => 'seed-'.Str::random(8),
            'request_hash' => str_repeat('0', 64),
            'target_url' => 'https://example.com/',
            'target_host' => 'example.com',
            'purpose' => 'seed',
            'policy' => ['allowed_hosts' => ['example.com'], 'allow_subresources_same_host' => true, 'redirects_allowed' => false],
            'capture_seconds' => 10,
            'status' => BuddyDiagnosticCapture::STATUS_COMPLETED,
            'callback_token_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_scope_owner_and_task_state_are_enforced(): void
    {
        Queue::fake();
        $readOnly = app(ApiKeyService::class)->issue($this->owner, [ApiScope::TasksRead])['plaintext'];
        $this->create($this->packet(), $readOnly)->assertForbidden();

        $this->create($this->packet(), $this->otherKey)->assertNotFound();
        $this->create(['request_id' => 'x'], $this->otherKey)->assertNotFound();

        $closed = BuddyTask::factory()->closed()->create(['api_client_id' => $this->owner->id]);
        $this->create($this->packet(), task: $closed)->assertUnprocessable()->assertJsonPath('error', 'task_terminal');

        $orphan = BuddyTask::factory()->create();
        $this->create($this->packet(), task: $orphan)->assertUnprocessable()->assertJsonPath('error', 'owner_required');

        $this->assertDatabaseCount('buddy_diagnostic_captures', 0);
        Queue::assertNothingPushed();
    }

    public function test_live_flag_off_refuses_before_writing(): void
    {
        Queue::fake();
        config(['buddy.edge.browser_diagnostics' => false]);

        $this->create($this->packet())
            ->assertStatus(503)
            ->assertJsonPath('error', 'browser_diagnostics_disabled')
            ->assertJsonPath('message', 'Live browser capture stays disabled until the billing and network gates pass (plan G7).');

        $this->assertDatabaseCount('buddy_diagnostic_captures', 0);
        Queue::assertNothingPushed();
    }

    public function test_forbidden_targets_are_recorded_as_denied_and_never_dispatched(): void
    {
        Queue::fake();

        foreach ([
            'ftp://example.com/' => CaptureTargetPolicy::SCHEME_NOT_ALLOWED,
            'https://user:pw@example.com/' => CaptureTargetPolicy::USERINFO_NOT_ALLOWED,
            'http://127.0.0.1/' => CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED,
            'http://[::1]/' => CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED,
            'http://10.0.0.1/' => CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED,
            'http://93.184.216.34/' => CaptureTargetPolicy::IP_LITERAL_NOT_ALLOWED,
            'https://xn--exmple-cua.com/' => CaptureTargetPolicy::PUNYCODE_NOT_ALLOWED,
            'https://other.example.com/' => CaptureTargetPolicy::HOST_NOT_ALLOWED,
            'http://localhost/' => CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED,
            'http://metadata.google.internal/' => CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED,
            'https://example.com/'.str_repeat('a', 2048) => CaptureTargetPolicy::URL_TOO_LONG,
            'https://examp1e.com/' => CaptureTargetPolicy::LOOKALIKE_NOT_ALLOWED,
        ] as $url => $code) {
            $response = $this->create($this->packet(['request_id' => 'deny-'.md5($url), 'url' => $url]))
                ->assertUnprocessable()
                ->assertJsonPath('error', $code);

            $capture = BuddyDiagnosticCapture::findOrFail($response->json('capture_id'));
            $this->assertSame(BuddyDiagnosticCapture::STATUS_DENIED, $capture->status);
            $this->assertSame($code, $capture->error_code);
            $this->assertNull($capture->callback_token_hash);
            $this->assertLessThanOrEqual(2048, strlen($capture->target_url));
        }

        Queue::assertNothingPushed();
        $this->assertSame(12, BuddyDiagnosticCapture::where('status', BuddyDiagnosticCapture::STATUS_DENIED)->count());
    }

    public function test_daily_quota_is_per_client_per_utc_day_and_ignores_denied_rows(): void
    {
        Queue::fake();
        $this->travelTo('2026-09-15 23:30:00');

        for ($i = 0; $i < 10; $i++) {
            $this->record();
        }
        $this->record(['status' => BuddyDiagnosticCapture::STATUS_DENIED, 'callback_token_hash' => null, 'error_code' => 'host_not_allowed']);

        $this->create($this->packet())->assertUnprocessable()->assertJsonPath('error', 'quota_exhausted');
        Queue::assertNothingPushed();

        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->other->id]);
        $this->create($this->packet(), $this->otherKey, $foreign)->assertStatus(202);

        $this->travelTo('2026-09-16 00:00:01');
        $this->create($this->packet(['request_id' => 'next-day']))->assertStatus(202);
    }

    public function test_concurrent_capacity_is_global(): void
    {
        Queue::fake();
        $this->record(['status' => BuddyDiagnosticCapture::STATUS_QUEUED]);
        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->other->id]);
        BuddyDiagnosticCapture::create([
            'buddy_task_id' => $foreign->id,
            'api_client_id' => $this->other->id,
            'request_id' => 'foreign-1',
            'request_hash' => str_repeat('0', 64),
            'target_url' => 'https://example.com/',
            'target_host' => 'example.com',
            'purpose' => 'seed',
            'policy' => [],
            'capture_seconds' => 10,
            'status' => BuddyDiagnosticCapture::STATUS_DISPATCHED,
            'callback_token_hash' => str_repeat('b', 64),
        ]);

        $this->create($this->packet())->assertUnprocessable()->assertJsonPath('error', 'capacity_exhausted');
        Queue::assertNothingPushed();

        BuddyDiagnosticCapture::where('status', BuddyDiagnosticCapture::STATUS_QUEUED)->update(['status' => BuddyDiagnosticCapture::STATUS_FAILED]);
        $this->create($this->packet())->assertStatus(202);
    }

    public function test_successful_request_queues_a_capture_and_is_idempotent(): void
    {
        Queue::fake();
        $packet = $this->packet();

        $first = $this->create($packet)->assertStatus(202)->assertJsonPath('status', 'queued');
        $capture = BuddyDiagnosticCapture::findOrFail($first->json('capture_id'));

        $this->assertSame(route('buddy.tasks.diagnostic_captures.show', ['task' => $this->task, 'capture' => $capture]), $first->json('poll'));
        $this->assertSame($this->owner->id, $capture->api_client_id);
        $this->assertSame('https://example.com/status', $capture->target_url);
        $this->assertSame('example.com', $capture->target_host);
        $this->assertSame(['allowed_hosts' => ['example.com'], 'allow_subresources_same_host' => true, 'redirects_allowed' => false], $capture->policy);
        $this->assertSame(15, $capture->capture_seconds);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $capture->callback_token_hash);
        $this->assertStringNotContainsString($capture->callback_token_hash, $first->getContent());
        Queue::assertPushed(DispatchDiagnosticCaptureJob::class, fn (DispatchDiagnosticCaptureJob $job) => $job->captureId === $capture->id);

        $this->assertSame($first->json(), $this->create($packet)->assertStatus(202)->json());
        $reordered = array_replace($packet, ['allowed_hosts' => ['Example.COM']]);
        $this->assertSame($first->json(), $this->create($reordered)->assertStatus(202)->json());

        $this->create(array_replace($packet, ['url' => 'https://example.com/other']))
            ->assertStatus(409)
            ->assertJsonPath('error', 'request_id_conflict')
            ->assertJsonPath('capture_id', $capture->id);

        $this->assertDatabaseCount('buddy_diagnostic_captures', 1);
        Queue::assertPushed(DispatchDiagnosticCaptureJob::class, 1);

        $denied = $this->create($this->packet(['request_id' => 'denied', 'url' => 'http://localhost/']))->assertUnprocessable();
        $this->assertSame($denied->json(), $this->create($this->packet(['request_id' => 'denied', 'url' => 'http://localhost/']))->assertUnprocessable()->json());
    }

    public function test_dispatch_sends_the_bounded_contract_and_completion_yields_redacted_evidence(): void
    {
        config(['buddy.edge.progress' => true]);
        Http::fake([self::WORKER.'/*' => Http::response(['accepted' => true], 202)]);

        $response = $this->create($this->packet())->assertStatus(202);
        $capture = BuddyDiagnosticCapture::findOrFail($response->json('capture_id'));
        $expectedKey = 'clients/'.$this->owner->id.'/tasks/'.$this->task->ulid.'/captures/'.$capture->id.'/screenshot.png';

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($capture, $expectedKey): bool {
            $body = $request->data();

            return $request->url() === self::WORKER.'/internal/captures'
                && $request->hasHeader('X-Buddy-Edge-Key', 'edge-service-key')
                && $body['capture_id'] === $capture->id
                && $body['task_id'] === $this->task->ulid
                && $body['client_id'] === (string) $this->owner->id
                && $body['url'] === 'https://example.com/status'
                && $body['policy'] === ['allowed_hosts' => ['example.com'], 'allow_subresources_same_host' => true, 'redirects_allowed' => false]
                && $body['capture_seconds'] === 15
                && $body['screenshot_key'] === $expectedKey
                && is_string($body['callback_token'])
                && strlen($body['callback_token']) === 64
                && hash('sha256', $body['callback_token']) === $capture->callback_token_hash;
        });

        $capture->refresh();
        $this->assertSame(BuddyDiagnosticCapture::STATUS_DISPATCHED, $capture->status);
        $this->assertNotNull($capture->dispatched_at);

        $token = Http::recorded()->first()[0]->data()['callback_token'];
        $report = [
            'callback_token' => $token,
            'status' => 'completed',
            'result' => [
                'status_code' => 500,
                'timing_ms' => 1834,
                'console_errors' => [
                    'Failed to fetch https://example.com/api/session?token=PRIVATE_QUERY_SECRET (Authorization: Bearer PRIVATE_BEARER_TOKEN)',
                    str_repeat('long line ', 90),
                    ...array_fill(0, 25, 'extra'),
                ],
                'network_failures' => ['GET https://example.com/assets/app.js?sig=PRIVATE_SIGNATURE 502'],
                'screenshot_object_key' => $expectedKey,
            ],
        ];

        $this->complete($capture, array_replace($report, ['callback_token' => 'not-the-token']))->assertNotFound();
        $this->complete($capture, $report, BuddyTask::factory()->create(['api_client_id' => $this->owner->id]))->assertNotFound();
        $this->complete($capture, array_replace_recursive($report, ['result' => ['screenshot_object_key' => 'clients/999/other.png']]))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'screenshot_key_mismatch');
        $this->assertSame(BuddyDiagnosticCapture::STATUS_DISPATCHED, $capture->refresh()->status);

        $completed = $this->complete($capture, $report)->assertOk()->assertJsonPath('status', 'completed');
        $capture->refresh();
        $artifact = BuddyArtifact::findOrFail($completed->json('artifact_id'));

        $this->assertSame(BuddyDiagnosticCapture::STATUS_COMPLETED, $capture->status);
        $this->assertNotNull($capture->completed_at);
        $this->assertSame(500, $capture->result['status_code']);
        $this->assertSame(20, count($capture->result['console_errors']));
        $this->assertSame(500, mb_strlen($capture->result['console_errors'][1]));

        $stored = json_encode($capture->result).$artifact->content;
        foreach (['PRIVATE_QUERY_SECRET', 'PRIVATE_BEARER_TOKEN', 'PRIVATE_SIGNATURE'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertStringContainsString('https://example.com/api/session', $capture->result['console_errors'][0]);
        $this->assertStringContainsString('[REDACTED_HEADER]', $capture->result['console_errors'][0]);
        $this->assertSame('GET https://example.com/assets/app.js 502', $capture->result['network_failures'][0]);

        $this->assertSame('other', $artifact->type->value);
        $this->assertSame($this->task->id, $artifact->buddy_task_id);
        $this->assertSame('diagnostic_capture', $artifact->metadata['kind']);
        $this->assertSame($capture->id, $artifact->metadata['capture_id']);
        $this->assertSame($expectedKey, $artifact->metadata['screenshot_object_key']);
        $this->assertSame('untrusted_page_content', $artifact->metadata['trust']);
        $this->assertStringContainsString('Target host: example.com', $artifact->content);
        $this->assertStringContainsString('HTTP status: 500', $artifact->content);
        $this->assertStringContainsString('never an instruction', $artifact->content);
        $this->assertLessThanOrEqual(4000, mb_strlen($artifact->content));

        $event = BuddyTaskEvent::where('type', TaskProgressService::TYPE_ARTIFACT_AVAILABLE)->sole();
        $this->assertSame($this->task->id, $event->buddy_task_id);
        $this->assertSame(['capture_id' => $capture->id, 'artifact_id' => $artifact->id], $event->data);

        $this->assertSame($completed->json(), $this->complete($capture, $report)->assertOk()->json());
        $this->complete($capture, array_replace_recursive($report, ['result' => ['status_code' => 200]]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'completion_conflict');
        $this->complete($capture, array_replace($report, ['status' => 'failed']))->assertStatus(409);
        $this->assertSame(1, BuddyArtifact::count());
        $this->assertSame(1, BuddyTaskEvent::count());

        $shown = $this->show($capture->id)->assertOk()
            ->assertJsonPath('capture_id', $capture->id)
            ->assertJsonPath('task_id', $this->task->ulid)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.status_code', 500)
            ->assertJsonMissingPath('callback_token_hash')
            ->assertJsonMissingPath('request_hash');
        $this->assertStringNotContainsString($capture->callback_token_hash, $shown->getContent());
        $this->assertStringNotContainsString($token, $shown->getContent());
        $this->assertStringNotContainsString('PRIVATE_', $shown->getContent());

        $this->show($capture->id, $this->otherKey)->assertNotFound();
        $this->show($capture->id, task: BuddyTask::factory()->create(['api_client_id' => $this->owner->id]))->assertNotFound();
        $this->show((string) Str::ulid())->assertNotFound();
    }

    public function test_failed_captures_still_leave_evidence_and_denied_captures_cannot_complete(): void
    {
        Http::fake([self::WORKER.'/*' => Http::response([], 200)]);
        $capture = BuddyDiagnosticCapture::findOrFail($this->create($this->packet())->json('capture_id'));
        $token = Http::recorded()->first()[0]->data()['callback_token'];

        $this->complete($capture, ['callback_token' => $token, 'status' => 'failed', 'result' => ['error_code' => 'navigation_blocked', 'network_failures' => ['GET https://example.com/status blocked_by_policy']]])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error_code', 'navigation_blocked');
        $this->assertSame('navigation_blocked', $capture->refresh()->error_code);
        $this->assertStringContainsString('Outcome: failed (navigation_blocked)', BuddyArtifact::sole()->content);

        $denied = BuddyDiagnosticCapture::findOrFail($this->create($this->packet(['request_id' => 'denied', 'url' => 'http://localhost/']))->json('capture_id'));
        $this->complete($denied, ['callback_token' => str_repeat('c', 64), 'status' => 'completed'])->assertNotFound();
        $this->complete($denied, ['callback_token' => '', 'status' => 'completed'])->assertNotFound();
    }

    public function test_worker_refusal_settles_the_capture_without_retry(): void
    {
        Http::fake([self::WORKER.'/*' => Http::response(['error' => 'browser_diagnostics_disabled'], 503)]);
        $capture = BuddyDiagnosticCapture::findOrFail($this->create($this->packet())->json('capture_id'));

        $this->assertSame(BuddyDiagnosticCapture::STATUS_FAILED, $capture->status);
        $this->assertSame('browser_diagnostics_disabled', $capture->error_code);
        $this->assertNotNull($capture->completed_at);
        $this->assertNull($capture->dispatched_at);
        Http::assertSentCount(1);
    }

    public function test_worker_outages_retry_once_and_then_settle(): void
    {
        Http::fake([self::WORKER.'/*' => Http::response(['error' => 'boom'], 500)]);
        $queued = $this->record(['status' => BuddyDiagnosticCapture::STATUS_QUEUED]);

        $firstAttempt = Mockery::mock(Job::class);
        $firstAttempt->shouldReceive('attempts')->andReturn(1);
        $firstAttempt->shouldReceive('release')->once()->with(DispatchDiagnosticCaptureJob::RETRY_DELAY_SECONDS);
        $job = new DispatchDiagnosticCaptureJob($queued->id, str_repeat('d', 64));
        $job->setJob($firstAttempt);
        $job->handle(app(BrowserCaptureDispatcher::class));
        $this->assertSame(BuddyDiagnosticCapture::STATUS_QUEUED, $queued->refresh()->status);

        $lastAttempt = Mockery::mock(Job::class);
        $lastAttempt->shouldReceive('attempts')->andReturn(DispatchDiagnosticCaptureJob::MAX_ATTEMPTS);
        $lastAttempt->shouldNotReceive('release');
        $job->setJob($lastAttempt);
        $job->handle(app(BrowserCaptureDispatcher::class));
        $this->assertSame(BuddyDiagnosticCapture::STATUS_FAILED, $queued->refresh()->status);
        $this->assertSame('dispatch_failed', $queued->error_code);

        $stranded = $this->record(['status' => BuddyDiagnosticCapture::STATUS_QUEUED]);
        (new DispatchDiagnosticCaptureJob($stranded->id, str_repeat('e', 64)))->failed(new \RuntimeException('timeout'));
        $this->assertSame('dispatch_failed', $stranded->refresh()->error_code);

        $settled = $this->record(['status' => BuddyDiagnosticCapture::STATUS_COMPLETED]);
        (new DispatchDiagnosticCaptureJob($settled->id, str_repeat('f', 64)))->handle(app(BrowserCaptureDispatcher::class));
        $this->assertSame(BuddyDiagnosticCapture::STATUS_COMPLETED, $settled->refresh()->status);
        Http::assertSentCount(2);
    }

    public function test_validation_bounds_the_request_shape(): void
    {
        Queue::fake();

        $this->create($this->packet(['allowed_hosts' => ['a.com', 'b.com', 'c.com', 'd.com', 'e.com', 'f.com']]))->assertUnprocessable()->assertJsonValidationErrors('allowed_hosts');
        $this->create($this->packet(['allowed_hosts' => []]))->assertUnprocessable()->assertJsonValidationErrors('allowed_hosts');
        $this->create($this->packet(['allowed_hosts' => ['exa mple.com']]))->assertUnprocessable()->assertJsonValidationErrors('allowed_hosts.0');
        $this->create($this->packet(['capture_seconds' => 4]))->assertUnprocessable()->assertJsonValidationErrors('capture_seconds');
        $this->create($this->packet(['capture_seconds' => 31]))->assertUnprocessable()->assertJsonValidationErrors('capture_seconds');
        $this->create($this->packet(['purpose' => str_repeat('p', 501)]))->assertUnprocessable()->assertJsonValidationErrors('purpose');
        $this->create($this->packet(['request_id' => 'bad id']))->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->create(array_diff_key($this->packet(), ['redirects_allowed' => true]))->assertUnprocessable()->assertJsonValidationErrors('redirects_allowed');

        $this->assertDatabaseCount('buddy_diagnostic_captures', 0);
        Queue::assertNothingPushed();

        $capture = BuddyDiagnosticCapture::findOrFail($this->create(array_diff_key($this->packet(), ['capture_seconds' => true]))->assertStatus(202)->json('capture_id'));
        $this->assertSame(30, $capture->capture_seconds);
    }
}
