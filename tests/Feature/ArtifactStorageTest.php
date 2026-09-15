<?php

namespace Tests\Feature;

use App\Contracts\ArtifactObjectStore;
use App\Enums\ApiScope;
use App\Jobs\ProcessArtifactJob;
use App\Jobs\PurgeArtifactObjectJob;
use App\Models\ApiClient;
use App\Models\BuddyArtifact;
use App\Models\BuddyArtifactQuota;
use App\Models\BuddyArtifactUpload;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Services\ApiKeyService;
use App\Services\Artifacts\ArtifactStorageService;
use App\Services\Edge\TaskProgressService;
use App\Services\Edge\ViewTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FakeArtifactObjectStore;
use Tests\TestCase;

class ArtifactStorageTest extends TestCase
{
    use RefreshDatabase;

    private FakeArtifactObjectStore $store;

    private ApiClient $owner;

    private ApiClient $other;

    private string $ownerKey;

    private string $otherKey;

    private BuddyTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'buddy.api.auth_required' => true,
            'buddy.edge.artifacts' => true,
            'buddy.edge.service_key' => 'edge-service-key',
        ]);
        $this->store = new FakeArtifactObjectStore;
        $this->app->instance(ArtifactObjectStore::class, $this->store);

        $service = app(ApiKeyService::class);
        $this->owner = ApiClient::create(['name' => 'owner', 'project' => 'buddy']);
        $this->other = ApiClient::create(['name' => 'other', 'project' => 'buddy']);
        $this->ownerKey = $service->issue($this->owner, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
        $this->otherKey = $service->issue($this->other, [ApiScope::TasksRead, ApiScope::TasksWrite])['plaintext'];
        $this->task = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
    }

    private function base(?BuddyTask $task = null): string
    {
        return '/api/buddy/tasks/'.($task ?? $this->task)->ulid;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reserve(array $overrides = [], ?string $key = null, ?BuddyTask $task = null): TestResponse
    {
        return $this->withToken($key ?? $this->ownerKey)->postJson($this->base($task).'/artifact-uploads', $overrides + [
            'type' => 'log',
            'size_bytes' => 5,
            'media_type' => 'text/plain',
        ]);
    }

    private function complete(string $uploadId, ?string $key = null, ?BuddyTask $task = null): TestResponse
    {
        return $this->withToken($key ?? $this->ownerKey)->postJson($this->base($task).'/artifact-uploads/'.$uploadId.'/complete');
    }

    private function reserved(int $size = 5, string $mediaType = 'text/plain', ?string $key = null, ?BuddyTask $task = null): BuddyArtifactUpload
    {
        $response = $this->reserve(['size_bytes' => $size, 'media_type' => $mediaType], $key, $task)->assertCreated();

        return BuddyArtifactUpload::findOrFail($response->json('upload_id'));
    }

    /*
     * Reserve, PUT the bytes the way a client would through the signed URL,
     * and finalize.
     */
    private function stored(string $bytes, string $mediaType = 'text/plain', ?string $key = null, ?BuddyTask $task = null): BuddyArtifact
    {
        $upload = $this->reserved(strlen($bytes), $mediaType, $key, $task);
        $this->store->put($upload->staging_key, $bytes);
        $completed = $this->complete($upload->id, $key, $task)->assertOk();

        return BuddyArtifact::findOrFail($completed->json('artifact_id'));
    }

    private function viewSession(?BuddyTask $task = null): string
    {
        $tickets = app(ViewTicketService::class);
        $ticket = $tickets->mint($task ?? $this->task, $this->owner)['ticket'];

        return $tickets->exchange($ticket)['token'];
    }

    private function edge(?string $sessionToken = null): static
    {
        $headers = ['X-Buddy-Edge-Key' => 'edge-service-key'];

        if ($sessionToken !== null) {
            $headers['X-Buddy-Edge-Session'] = $sessionToken;
        }

        return $this->withHeaders($headers);
    }

    private function internalSummary(BuddyArtifact $artifact, ?BuddyTask $task = null): string
    {
        return '/api/internal/cloudflare/tasks/'.($task ?? $this->task)->ulid.'/artifacts/'.$artifact->id.'/summary';
    }

    public function test_every_route_is_absent_while_the_flag_is_off(): void
    {
        $artifact = $this->stored('hello');
        $upload = BuddyArtifactUpload::firstOrFail();
        $session = $this->viewSession();
        config(['buddy.edge.artifacts' => false]);

        $this->reserve()->assertNotFound();
        $this->reserve(['media_type' => ''])->assertNotFound();
        $this->complete($upload->id)->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/download')->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/summary')->assertNotFound();
        $this->withToken($this->ownerKey)->deleteJson($this->base().'/artifacts/'.$artifact->id)->assertNotFound();
        $this->edge($session)->getJson($this->internalSummary($artifact))->assertNotFound();

        $this->assertNull($artifact->fresh()->deleted_at);
        $this->assertSame(1, BuddyArtifactUpload::count());
    }

    public function test_a_non_owner_gets_404_on_every_route(): void
    {
        $artifact = $this->stored('hello');
        $upload = BuddyArtifactUpload::firstOrFail();
        $foreignTask = BuddyTask::factory()->create(['api_client_id' => $this->other->id]);
        $foreignArtifact = $this->stored('theirs', 'text/plain', $this->otherKey, $foreignTask);
        $foreignUpload = $this->reserved(5, 'text/plain', $this->otherKey, $foreignTask);

        $this->reserve([], $this->otherKey)->assertNotFound();
        $this->reserve(['media_type' => ''], $this->otherKey)->assertNotFound();
        $this->complete($upload->id, $this->otherKey)->assertNotFound();
        $this->withToken($this->otherKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/download')->assertNotFound();
        $this->withToken($this->otherKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/summary')->assertNotFound();
        $this->withToken($this->otherKey)->deleteJson($this->base().'/artifacts/'.$artifact->id)->assertNotFound();

        // Owning one task never reaches another task's uploads or artifacts.
        $this->complete($foreignUpload->id)->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$foreignArtifact->id.'/summary?metadata_only=1')->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$foreignArtifact->id.'/download')->assertNotFound();
        $this->withToken($this->ownerKey)->deleteJson($this->base().'/artifacts/'.$foreignArtifact->id)->assertNotFound();
        $this->edge($this->viewSession())->getJson($this->internalSummary($foreignArtifact))->assertNotFound();

        $this->assertNull($artifact->fresh()->deleted_at);
        $this->assertNull($foreignArtifact->fresh()->deleted_at);
        $this->assertSame('reserved', $foreignUpload->fresh()->status);
    }

    public function test_unsupported_media_types_and_terminal_tasks_are_rejected(): void
    {
        foreach (['text/html', 'image/svg+xml', 'application/octet-stream', 'application/x-msdownload', 'application/pdf'] as $type) {
            $this->reserve(['media_type' => $type])
                ->assertStatus(422)
                ->assertJsonPath('error', 'unsupported_media_type');
        }

        $this->reserve(['type' => 'code'])->assertStatus(422);
        $this->reserve(['size_bytes' => 0])->assertStatus(422);
        $this->assertSame(0, BuddyArtifactUpload::count());
        $this->assertSame(0, BuddyArtifactQuota::count());

        $this->reserve(['media_type' => 'application/x-gzip; charset=binary'])
            ->assertCreated()
            ->assertJsonPath('media_type', 'application/gzip');

        $closed = BuddyTask::factory()->closed()->create(['api_client_id' => $this->owner->id]);
        $this->reserve([], null, $closed)->assertStatus(422)->assertJsonPath('error', 'task_terminal');
    }

    public function test_uploads_over_the_per_upload_cap_are_rejected(): void
    {
        $this->reserve(['size_bytes' => 26214401])
            ->assertStatus(422)
            ->assertJsonPath('error', 'upload_too_large')
            ->assertJsonPath('max_bytes', 26214400);
        $this->reserve(['size_bytes' => 26214400])->assertCreated();

        $this->assertSame(1, BuddyArtifactUpload::count());
        $this->assertSame(26214400, (int) BuddyArtifactQuota::sum('reserved_bytes'));
    }

    public function test_task_quota_counts_ready_and_reserved_bytes_and_releases_on_expiry(): void
    {
        config(['buddy.edge.quotas.task_bytes' => 1000, 'buddy.edge.quotas.upload_bytes' => 600]);

        $this->stored(str_repeat('a', 500));
        $this->reserve(['size_bytes' => 400])->assertCreated();
        $this->reserve(['size_bytes' => 200])
            ->assertStatus(422)
            ->assertJsonPath('error', 'quota_exhausted')
            ->assertJsonPath('limit', 'task')
            ->assertJsonPath('max', 1000);

        // The cap is per task: a sibling task of the same client is unaffected.
        $sibling = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
        $this->reserve(['size_bytes' => 600], null, $sibling)->assertCreated();
        $this->assertSame(1000, (int) BuddyArtifactQuota::sum('reserved_bytes'));
        $this->assertSame(500, (int) BuddyArtifactQuota::sum('committed_bytes'));

        $this->travel(301)->seconds();
        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('Expired 2 reservation(s)');

        $this->assertSame(0, (int) BuddyArtifactQuota::sum('reserved_bytes'));
        $this->assertSame(2, BuddyArtifactUpload::where('status', 'expired')->count());
        $this->reserve(['size_bytes' => 500])->assertCreated();
        $this->reserve(['size_bytes' => 1])->assertStatus(422)->assertJsonPath('limit', 'task');
    }

    public function test_client_daily_quota_counts_committed_bytes_and_releases_reservations_on_expiry(): void
    {
        config(['buddy.edge.quotas.client_daily_bytes' => 1000, 'buddy.edge.quotas.upload_bytes' => 600]);
        $sibling = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);

        $this->stored(str_repeat('a', 600));
        $this->reserve(['size_bytes' => 300], null, $sibling)->assertCreated();
        $this->reserve(['size_bytes' => 200])
            ->assertStatus(422)
            ->assertJsonPath('error', 'quota_exhausted')
            ->assertJsonPath('limit', 'client_daily')
            ->assertJsonPath('max', 1000);

        // Another client spends its own allowance.
        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->other->id]);
        $this->reserve(['size_bytes' => 600], $this->otherKey, $foreign)->assertCreated();

        $this->travel(301)->seconds();
        $this->artisan('buddy:artifacts:cleanup')->assertSuccessful();

        $this->assertSame(0, (int) BuddyArtifactQuota::sum('reserved_bytes'));
        $this->assertSame(600, (int) BuddyArtifactQuota::sum('committed_bytes'));
        $this->reserve(['size_bytes' => 200])->assertCreated();
        $this->reserve(['size_bytes' => 300], null, $sibling)->assertStatus(422)->assertJsonPath('limit', 'client_daily');
    }

    public function test_active_reservations_are_capped_per_client(): void
    {
        config(['buddy.edge.quotas.active_reservations' => 2]);

        $first = $this->reserved();
        $this->reserved();
        $this->reserve()
            ->assertStatus(422)
            ->assertJsonPath('error', 'quota_exhausted')
            ->assertJsonPath('limit', 'active_reservations')
            ->assertJsonPath('max', 2);

        $this->store->put($first->staging_key, 'hello');
        $this->complete($first->id)->assertOk();
        $this->reserve()->assertCreated();
        $this->reserve()->assertStatus(422)->assertJsonPath('limit', 'active_reservations');
    }

    public function test_finalize_without_an_object_is_a_retryable_conflict(): void
    {
        $upload = $this->reserved();

        $this->complete($upload->id)->assertStatus(409)->assertJsonPath('error', 'object_missing');
        $this->assertSame('reserved', $upload->fresh()->status);
        $this->assertSame(0, BuddyArtifact::count());
        $this->assertSame(5, (int) BuddyArtifactQuota::sum('reserved_bytes'));

        $this->store->put($upload->staging_key, 'hello');
        $this->complete($upload->id)->assertOk();
    }

    public function test_size_and_signature_mismatches_fail_the_upload_without_an_artifact(): void
    {
        $short = $this->reserved(5);
        $this->store->put($short->staging_key, 'hello world');
        $this->complete($short->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'upload_failed')
            ->assertJsonPath('reason', 'size_mismatch');
        $this->assertSame('failed', $short->fresh()->status);
        $this->assertFalse($this->store->has($short->staging_key));
        $this->complete($short->id)->assertStatus(422)->assertJsonPath('reason', 'previous_failure');

        $png = $this->reserved(5, 'image/png');
        $this->store->put($png->staging_key, 'hello');
        $this->complete($png->id)->assertStatus(422)->assertJsonPath('reason', 'media_type_mismatch');

        $json = $this->reserved(9, 'application/json');
        $this->store->put($json->staging_key, 'not json!');
        $this->complete($json->id)->assertStatus(422)->assertJsonPath('reason', 'media_type_mismatch');

        $binary = $this->reserved(5, 'text/plain');
        $this->store->put($binary->staging_key, "he\0lo");
        $this->complete($binary->id)->assertStatus(422)->assertJsonPath('reason', 'media_type_mismatch');

        $zip = $this->reserved(5, 'application/zip');
        $this->store->put($zip->staging_key, 'hello');
        $this->complete($zip->id)->assertStatus(422)->assertJsonPath('reason', 'media_type_mismatch');

        config(['buddy.edge.quotas.upload_bytes' => 4]);
        $oversized = $this->reserved(4);
        $this->store->put($oversized->staging_key, 'hello');
        $this->complete($oversized->id)->assertStatus(422)->assertJsonPath('error', 'upload_too_large');

        $this->assertSame(0, BuddyArtifact::count());
        $this->assertSame(6, BuddyArtifactUpload::where('status', 'failed')->count());
        $this->assertSame(0, (int) BuddyArtifactQuota::sum('reserved_bytes'));
        $this->assertSame(0, (int) BuddyArtifactQuota::sum('committed_bytes'));
        $this->assertSame([], $this->store->keys());
        Queue::assertNothingPushed();
    }

    public function test_a_verified_upload_becomes_a_ready_artifact(): void
    {
        config(['buddy.edge.progress' => true]);
        $bytes = "line one\nline two\n";

        $reserved = $this->reserve(['size_bytes' => strlen($bytes), 'type' => 'test_output'])
            ->assertCreated()
            ->assertJsonPath('task_id', $this->task->ulid)
            ->assertJsonPath('type', 'test_output')
            ->assertJsonPath('upload.method', 'PUT')
            ->assertJsonPath('upload.headers.Content-Type', 'text/plain')
            ->assertJsonPath('upload.headers.Content-Length', (string) strlen($bytes));
        $upload = BuddyArtifactUpload::findOrFail($reserved->json('upload_id'));

        $this->assertSame(sprintf('clients/%d/tasks/%s/staging/%s', $this->owner->id, $this->task->ulid, $upload->id), $upload->staging_key);
        $this->assertStringContainsString($upload->staging_key, $reserved->json('upload.url'));
        $this->assertStringContainsString('/artifact-uploads/'.$upload->id.'/complete', $reserved->json('complete_url'));
        $this->assertSame(300, $this->store->signed[0]['expires']);
        $this->assertTrue($upload->expires_at->between(now()->addSeconds(299), now()->addSeconds(301)));

        $this->store->put($upload->staging_key, $bytes);
        $completed = $this->complete($upload->id)
            ->assertOk()
            ->assertJsonPath('task_id', $this->task->ulid)
            ->assertJsonPath('type', 'test_output')
            ->assertJsonPath('media_type', 'text/plain')
            ->assertJsonPath('size_bytes', strlen($bytes))
            ->assertJsonPath('content_hash', hash('sha256', $bytes))
            ->assertJsonPath('storage_status', 'ready');
        $artifact = BuddyArtifact::findOrFail($completed->json('artifact_id'));

        $this->assertStringStartsWith(sprintf('clients/%d/tasks/%s/artifacts/', $this->owner->id, $this->task->ulid), $artifact->object_key);
        $this->assertFalse($this->store->has($upload->staging_key));
        $this->assertSame($bytes, $this->store->get($artifact->object_key));
        $this->assertSame(hash('sha256', $bytes), $artifact->sha256);
        $this->assertSame('pending', $artifact->processing_status);
        $this->assertNull($artifact->processor_version);
        $this->assertSame(ArtifactStorageService::PENDING_CONTENT, $artifact->content);
        $this->assertTrue($artifact->retention_until->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()));
        $this->assertSame('finalized', $upload->fresh()->status);
        $this->assertSame($artifact->id, $upload->fresh()->buddy_artifact_id);

        $quota = BuddyArtifactQuota::firstOrFail();
        $this->assertSame(0, $quota->reserved_bytes);
        $this->assertSame(strlen($bytes), $quota->committed_bytes);

        Queue::assertPushed(ProcessArtifactJob::class, fn (ProcessArtifactJob $job) => $job->artifactId === $artifact->id && $job->sha256 === hash('sha256', $bytes));

        $event = BuddyTaskEvent::where('type', TaskProgressService::TYPE_ARTIFACT_AVAILABLE)->firstOrFail();
        $this->assertSame($artifact->id, $event->data['artifact_id']);
        $this->assertSame('text/plain', $event->data['media_type']);
        $this->assertSame(strlen($bytes), $event->data['size_bytes']);
        $this->assertArrayNotHasKey('url', $event->data);
    }

    public function test_finalize_is_idempotent_and_the_staging_key_cannot_be_reused(): void
    {
        $upload = $this->reserved(8);
        $this->store->put($upload->staging_key, 'original');
        $first = $this->complete($upload->id)->assertOk()->json();
        $artifact = BuddyArtifact::findOrFail($first['artifact_id']);

        // The signed URL is still valid, so a client can re-PUT to staging;
        // the final key is immutable and the receipt does not change.
        $this->store->put($upload->staging_key, 'tampered');
        $second = $this->complete($upload->id)->assertOk()->json();

        $this->assertSame($first, $second);
        $this->assertSame(1, BuddyArtifact::count());
        $this->assertSame('original', $this->store->get($artifact->object_key));
        $this->assertSame(hash('sha256', 'original'), $artifact->fresh()->sha256);
        $this->assertSame(8, (int) BuddyArtifactQuota::sum('committed_bytes'));
        Queue::assertPushed(ProcessArtifactJob::class, 1);

        $this->travel(301)->seconds();
        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('swept 1 staging object(s)');
        $this->assertFalse($this->store->has($upload->staging_key));
        $this->assertSame('original', $this->store->get($artifact->object_key));
    }

    public function test_download_urls_exist_only_while_ready_and_deletion_tombstones_immediately(): void
    {
        $artifact = $this->stored('hello');
        $download = $this->base().'/artifacts/'.$artifact->id.'/download';
        $summary = $this->base().'/artifacts/'.$artifact->id.'/summary';
        $session = $this->viewSession();

        $response = $this->withToken($this->ownerKey)->getJson($download)
            ->assertOk()
            ->assertJsonPath('artifact_id', $artifact->id)
            ->assertJsonPath('filename', 'artifact-'.$artifact->id.'.txt')
            ->assertJsonPath('media_type', 'text/plain')
            ->assertJsonPath('content_hash', hash('sha256', 'hello'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment; filename="artifact-'.$artifact->id.'.txt"', urldecode($response->json('url')));
        $this->assertStringContainsString('response-content-type=text%2Fplain', $response->json('url'));
        $this->assertSame(120, end($this->store->signed)['expires']);

        $deleted = $this->withToken($this->ownerKey)->deleteJson($this->base().'/artifacts/'.$artifact->id)
            ->assertOk()
            ->assertJsonPath('storage_status', 'deleted');
        $fresh = $artifact->fresh();
        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame(ArtifactStorageService::DELETED_CONTENT, $fresh->content);
        // The tombstone is authoritative before any purge has run.
        $this->assertTrue($this->store->has($artifact->object_key));

        $this->withToken($this->ownerKey)->getJson($download)->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($summary)->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($summary.'?metadata_only=1')->assertNotFound();
        $this->edge($session)->getJson($this->internalSummary($artifact))->assertNotFound();

        $this->withToken($this->ownerKey)->deleteJson($this->base().'/artifacts/'.$artifact->id)
            ->assertOk()
            ->assertJsonPath('deleted_at', $deleted->json('deleted_at'));
        Queue::assertPushed(PurgeArtifactObjectJob::class, 1);

        $this->store->failDeletes = true;

        try {
            app()->call([new PurgeArtifactObjectJob($artifact->id), 'handle']);
            $this->fail('A failed object delete must throw so the job retries.');
        } catch (RuntimeException) {
        }

        $this->assertSame('deleted', $artifact->fresh()->storage_status);
        $this->assertTrue($this->store->has($artifact->object_key));

        $this->store->failDeletes = false;
        app()->call([new PurgeArtifactObjectJob($artifact->id), 'handle']);
        $this->assertFalse($this->store->has($artifact->object_key));
        $this->assertSame('purged', $artifact->fresh()->storage_status);

        app()->call([new PurgeArtifactObjectJob($artifact->id), 'handle']);
        $this->withToken($this->ownerKey)->getJson($download)->assertNotFound();
        $this->assertSame([], $this->store->keys());
    }

    public function test_store_outages_are_reported_as_dependency_unavailable(): void
    {
        $this->store->unavailable = true;
        $this->reserve()->assertStatus(503)->assertJsonPath('error', 'dependency_unavailable');
        $this->assertSame(0, BuddyArtifactUpload::whereIn('status', ['reserved', 'uploaded'])->count());
        $this->assertSame(0, (int) BuddyArtifactQuota::sum('reserved_bytes'));

        $this->store->unavailable = false;
        $upload = $this->reserved();
        $this->store->put($upload->staging_key, 'hello');

        $this->store->unavailable = true;
        $this->complete($upload->id)->assertStatus(503)->assertJsonPath('error', 'dependency_unavailable');
        $this->assertSame('reserved', $upload->fresh()->status);
        $this->assertSame(0, BuddyArtifact::count());

        $this->store->unavailable = false;
        $this->complete($upload->id)->assertOk();

        $artifact = BuddyArtifact::firstOrFail();
        $this->store->unavailable = true;
        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/download')->assertStatus(503);
    }

    public function test_cleanup_expires_reservations_and_retires_artifacts_past_retention(): void
    {
        $upload = $this->reserved();
        $this->store->put($upload->staging_key, 'hello');
        $artifact = $this->stored('keep me');

        $this->artisan('buddy:artifacts:cleanup --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Expired 0 reservation(s), retired 0 artifact(s), re-queued 0 purge(s), swept 0 staging object(s) (dry run)');

        $this->travel(301)->seconds();
        $this->artisan('buddy:artifacts:cleanup --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Expired 1 reservation(s), retired 0 artifact(s), re-queued 0 purge(s), swept 1 staging object(s) (dry run)');
        $this->assertSame('reserved', $upload->fresh()->status);
        $this->assertTrue($this->store->has($upload->staging_key));

        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('Expired 1 reservation(s), retired 0 artifact(s), re-queued 0 purge(s), swept 1 staging object(s).');
        $this->assertSame('expired', $upload->fresh()->status);
        $this->assertFalse($this->store->has($upload->staging_key));
        $this->assertSame(0, (int) BuddyArtifactQuota::sum('reserved_bytes'));
        $this->assertSame(7, (int) BuddyArtifactQuota::sum('committed_bytes'));
        $this->complete($upload->id)->assertStatus(410)->assertJsonPath('error', 'reservation_expired');

        $this->travel(31)->days();
        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('Expired 0 reservation(s), retired 1 artifact(s), re-queued 0 purge(s)');
        $this->assertSame('deleted', $artifact->fresh()->storage_status);
        $this->assertNotNull($artifact->fresh()->deleted_at);
        Queue::assertPushed(PurgeArtifactObjectJob::class, 1);

        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('Expired 0 reservation(s), retired 0 artifact(s), re-queued 0 purge(s)');

        $this->travel(2)->days();
        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('re-queued 1 purge(s)');
        Queue::assertPushed(PurgeArtifactObjectJob::class, 2);
    }

    public function test_summaries_are_bounded_and_served_to_owners_and_view_sessions(): void
    {
        $bytes = str_repeat('x', 10);
        $artifact = $this->stored($bytes);
        $summary = $this->base().'/artifacts/'.$artifact->id.'/summary';

        $this->withToken($this->ownerKey)->getJson($summary)->assertStatus(409)->assertJsonPath('error', 'processing_pending');
        $this->withToken($this->ownerKey)->getJson($summary.'?metadata_only=1')
            ->assertOk()
            ->assertJsonPath('processing_status', 'pending')
            ->assertJsonPath('processor_version', null)
            ->assertJsonPath('content_hash', hash('sha256', $bytes))
            ->assertJsonMissingPath('summary');

        $artifact->forceFill([
            'content' => str_repeat('s', 3000),
            'processing_status' => 'completed',
            'processor_version' => 'v1',
        ])->save();

        $full = $this->withToken($this->ownerKey)->getJson($summary)->assertOk();
        $this->assertSame([
            'artifact_id',
            'task_id',
            'type',
            'media_type',
            'size_bytes',
            'content_hash',
            'processor_version',
            'processing_status',
            'storage_status',
            'summary',
        ], array_keys($full->json()));
        $this->assertSame($this->task->ulid, $full->json('task_id'));
        $this->assertSame('log', $full->json('type'));
        $this->assertSame(10, $full->json('size_bytes'));
        $this->assertSame('v1', $full->json('processor_version'));
        $this->assertSame('ready', $full->json('storage_status'));
        $this->assertSame(2000, mb_strlen($full->json('summary')));

        $internal = $this->internalSummary($artifact);
        $this->getJson($internal)->assertUnauthorized();
        $this->edge()->getJson($internal)->assertUnauthorized()->assertJsonPath('error', 'session_expired');
        $this->edge('bvs_nope')->getJson($internal)->assertUnauthorized()->assertJsonPath('error', 'session_expired');

        $session = $this->viewSession();
        $this->assertSame($full->json(), $this->edge($session)->getJson($internal)->assertOk()->json());
        $this->edge($session)->getJson($internal.'?metadata_only=1')
            ->assertOk()
            ->assertJsonPath('content_hash', hash('sha256', $bytes))
            ->assertJsonPath('processor_version', 'v1')
            ->assertJsonMissingPath('summary');

        $foreign = BuddyTask::factory()->create(['api_client_id' => $this->owner->id]);
        $this->edge($this->viewSession($foreign))->getJson($internal)->assertUnauthorized();
        $this->edge($session)->getJson($this->internalSummary($artifact, $foreign))->assertUnauthorized();

        $this->owner->update(['active' => false]);
        $this->edge($session)->getJson($internal)->assertUnauthorized();
    }

    public function test_legacy_inline_artifacts_still_attach_and_have_no_storage_surface(): void
    {
        $response = $this->withToken($this->ownerKey)
            ->postJson($this->base().'/artifacts', ['type' => 'log', 'content' => 'ERROR at line 42'])
            ->assertCreated()
            ->assertJsonStructure(['id', 'type', 'created_at']);
        $artifact = BuddyArtifact::findOrFail($response->json('id'));

        $this->assertNull($artifact->storage_status);
        $this->assertNull($artifact->object_key);
        $this->assertSame('ERROR at line 42', $artifact->content);

        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/summary')->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/summary?metadata_only=1')->assertNotFound();
        $this->withToken($this->ownerKey)->getJson($this->base().'/artifacts/'.$artifact->id.'/download')->assertNotFound();
        $this->withToken($this->ownerKey)->deleteJson($this->base().'/artifacts/'.$artifact->id)->assertNotFound();
        $this->edge($this->viewSession())->getJson($this->internalSummary($artifact))->assertNotFound();
        $this->assertNull($artifact->fresh()->deleted_at);
        $this->assertSame('ERROR at line 42', $artifact->fresh()->content);

        config(['buddy.edge.artifacts' => false]);
        $this->withToken($this->ownerKey)
            ->postJson($this->base().'/artifacts', ['type' => 'stacktrace', 'content' => 'at main()'])
            ->assertCreated();
        $this->assertSame(2, $this->task->artifacts()->count());
        Queue::assertNothingPushed();
    }
}
