<?php

namespace Tests\Feature;

use App\Contracts\ArtifactObjectStore;
use App\Services\Artifacts\R2ObjectStore;
use App\Services\Artifacts\WorkerProxyObjectStore;
use App\Services\Edge\EdgeTokenSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WorkerProxyObjectStoreTest extends TestCase
{
    private const WORKER = 'https://edge.example';

    private const KEY = 'clients/1/tasks/01TASK/staging/01UPLOAD';

    private EdgeTokenSigner $signer;

    private WorkerProxyObjectStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['buddy.edge.service_key' => 'svc-key']);
        $this->signer = new EdgeTokenSigner;
        $this->store = new WorkerProxyObjectStore(self::WORKER.'/', 'svc-key', $this->signer);
    }

    public function test_presign_upload_returns_a_worker_url_carrying_a_verifiable_token(): void
    {
        $signed = $this->store->presignUpload(self::KEY, 300, 'text/plain', 5);

        $this->assertStringStartsWith(self::WORKER.'/uploads/bup1.', $signed['url']);
        $this->assertSame(['Content-Type' => 'text/plain'], $signed['headers']);

        $payload = $this->signer->verify(substr($signed['url'], strlen(self::WORKER.'/uploads/')), EdgeTokenSigner::KIND_UPLOAD);

        $this->assertNotNull($payload);
        $this->assertSame(self::KEY, $payload['key']);
        $this->assertSame(5, $payload['max_bytes']);
        $this->assertSame('text/plain', $payload['content_type']);
        $this->assertSame('01UPLOAD', $payload['upload_id']);
        $this->assertSame(now()->addSeconds(300)->getTimestamp(), $payload['exp']);
        Http::assertNothingSent();
    }

    public function test_head_returns_size_and_content_type(): void
    {
        Http::fake([self::WORKER.'/internal/objects/meta*' => Http::response(['size' => 5, 'content_type' => 'text/plain', 'etag' => 'abc'], 200)]);

        $this->assertSame(['size' => 5, 'content_type' => 'text/plain'], $this->store->head(self::KEY));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::WORKER.'/internal/objects/meta?key='.urlencode(self::KEY)
            && $request->hasHeader('X-Buddy-Edge-Key', 'svc-key'));
    }

    public function test_head_normalizes_an_empty_content_type_to_null(): void
    {
        Http::fake([self::WORKER.'/internal/objects/meta*' => Http::response(['size' => '7', 'content_type' => '', 'etag' => 'abc'], 200)]);

        $this->assertSame(['size' => 7, 'content_type' => null], $this->store->head(self::KEY));
    }

    public function test_head_returns_null_for_a_missing_object(): void
    {
        Http::fake([self::WORKER.'/internal/objects/meta*' => Http::response(['error' => 'not_found'], 404)]);

        $this->assertNull($this->store->head(self::KEY));
    }

    public function test_head_throws_on_a_worker_failure(): void
    {
        Http::fake([self::WORKER.'/internal/objects/meta*' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->store->head(self::KEY);
    }

    public function test_read_stream_yields_the_object_bytes(): void
    {
        Http::fake([self::WORKER.'/internal/objects/content*' => Http::response('hello, world', 200, ['Content-Type' => 'text/plain'])]);

        $stream = $this->store->readStream(self::KEY);

        $this->assertIsResource($stream);
        $this->assertSame('hello, world', stream_get_contents($stream));
        fclose($stream);

        Http::assertSent(fn (Request $request) => $request->url() === self::WORKER.'/internal/objects/content?key='.urlencode(self::KEY)
            && $request->hasHeader('X-Buddy-Edge-Key', 'svc-key'));
    }

    public function test_read_stream_returns_null_for_a_missing_object(): void
    {
        Http::fake([self::WORKER.'/internal/objects/content*' => Http::response('', 404)]);

        $this->assertNull($this->store->readStream(self::KEY));
    }

    public function test_read_stream_throws_on_a_worker_failure(): void
    {
        Http::fake([self::WORKER.'/internal/objects/content*' => Http::response('', 503)]);

        $this->expectException(RuntimeException::class);

        $this->store->readStream(self::KEY);
    }

    public function test_copy_posts_both_keys_and_reports_success(): void
    {
        Http::fake([self::WORKER.'/internal/objects/copy' => Http::response(['copied' => true], 200)]);

        $this->assertTrue($this->store->copy(self::KEY, 'clients/1/tasks/01TASK/artifacts/01FINAL'));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::WORKER.'/internal/objects/copy'
            && $request->hasHeader('X-Buddy-Edge-Key', 'svc-key')
            && $request['from'] === self::KEY
            && $request['to'] === 'clients/1/tasks/01TASK/artifacts/01FINAL');
    }

    public function test_copy_reports_a_missing_source_as_false(): void
    {
        Http::fake([self::WORKER.'/internal/objects/copy' => Http::response(['error' => 'not_found'], 404)]);

        $this->assertFalse($this->store->copy(self::KEY, 'elsewhere'));
    }

    public function test_copy_throws_on_a_worker_failure(): void
    {
        Http::fake([self::WORKER.'/internal/objects/copy' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);

        $this->store->copy(self::KEY, 'elsewhere');
    }

    public function test_delete_succeeds_on_no_content(): void
    {
        Http::fake([self::WORKER.'/internal/objects?*' => Http::response('', 204)]);

        $this->assertTrue($this->store->delete(self::KEY));

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === self::WORKER.'/internal/objects?key='.urlencode(self::KEY)
            && $request->hasHeader('X-Buddy-Edge-Key', 'svc-key'));
    }

    public function test_delete_treats_a_missing_object_as_deleted(): void
    {
        Http::fake([self::WORKER.'/internal/objects?*' => Http::response('', 404)]);

        $this->assertTrue($this->store->delete(self::KEY));
    }

    public function test_delete_reports_a_worker_failure_as_false(): void
    {
        Http::fake([self::WORKER.'/internal/objects?*' => Http::response('', 500)]);

        $this->assertFalse($this->store->delete(self::KEY));
    }

    public function test_presign_download_returns_a_worker_url_carrying_a_verifiable_token(): void
    {
        $url = $this->store->presignDownload('clients/1/tasks/01TASK/artifacts/01FINAL', 120, 'artifact-7.txt', 'text/plain');

        $this->assertStringStartsWith(self::WORKER.'/downloads/bdl1.', $url);

        $payload = $this->signer->verify(substr($url, strlen(self::WORKER.'/downloads/')), EdgeTokenSigner::KIND_DOWNLOAD);

        $this->assertNotNull($payload);
        $this->assertSame('clients/1/tasks/01TASK/artifacts/01FINAL', $payload['key']);
        $this->assertSame('artifact-7.txt', $payload['filename']);
        $this->assertSame('text/plain', $payload['content_type']);
        $this->assertSame(now()->addSeconds(120)->getTimestamp(), $payload['exp']);
        Http::assertNothingSent();
    }

    public function test_the_container_binds_the_worker_proxy_only_without_an_r2_key(): void
    {
        config([
            'buddy.edge.worker_url' => self::WORKER,
            'buddy.edge.service_key' => 'svc-key',
            'filesystems.disks.r2.key' => null,
        ]);
        $this->assertInstanceOf(WorkerProxyObjectStore::class, $this->app->make(ArtifactObjectStore::class));

        $this->app->forgetInstance(ArtifactObjectStore::class);
        config(['filesystems.disks.r2.key' => 'r2-access-key', 'filesystems.disks.r2.secret' => 'r2-secret']);
        $this->assertInstanceOf(R2ObjectStore::class, $this->app->make(ArtifactObjectStore::class));

        $this->app->forgetInstance(ArtifactObjectStore::class);
        config(['buddy.edge.worker_url' => null, 'filesystems.disks.r2.key' => null]);
        $this->assertInstanceOf(R2ObjectStore::class, $this->app->make(ArtifactObjectStore::class));
    }
}
