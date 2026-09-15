<?php

namespace Tests\Feature;

use App\Contracts\ArtifactObjectStore;
use App\Jobs\ProcessArtifactJob;
use App\Models\ApiClient;
use App\Models\BuddyArtifact;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Services\Artifacts\ArtifactStorageService;
use App\Services\Edge\TaskProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeArtifactObjectStore;
use Tests\TestCase;
use ZipArchive;

class ArtifactProcessingTest extends TestCase
{
    use RefreshDatabase;

    private FakeArtifactObjectStore $store;

    private BuddyTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'buddy.edge.artifacts' => true,
            'buddy.edge.progress' => true,
        ]);
        $this->store = new FakeArtifactObjectStore;
        $this->app->instance(ArtifactObjectStore::class, $this->store);

        $client = ApiClient::create(['name' => 'owner', 'project' => 'buddy']);
        $this->task = BuddyTask::factory()->create(['api_client_id' => $client->id]);
    }

    private function artifact(string $bytes, string $mediaType, bool $storeObject = true): BuddyArtifact
    {
        $key = sprintf('clients/%d/tasks/%s/artifacts/%s', $this->task->api_client_id, $this->task->ulid, Str::ulid());

        if ($storeObject) {
            $this->store->put($key, $bytes);
        }

        return BuddyArtifact::create([
            'buddy_task_id' => $this->task->id,
            'type' => 'log',
            'content' => ArtifactStorageService::PENDING_CONTENT,
            'object_key' => $key,
            'size_bytes' => strlen($bytes),
            'media_type' => $mediaType,
            'sha256' => hash('sha256', $bytes),
            'storage_status' => 'ready',
            'processing_status' => 'pending',
            'retention_until' => now()->addDays(30),
        ]);
    }

    private function process(BuddyArtifact $artifact): BuddyArtifact
    {
        app()->call([new ProcessArtifactJob($artifact->id, (string) $artifact->sha256), 'handle']);

        return $artifact->fresh();
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zip(array $entries, ?string $password = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'buddy-test-zip-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);

            if ($password !== null) {
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
            }
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function processedEvent(BuddyArtifact $artifact): BuddyTaskEvent
    {
        return BuddyTaskEvent::query()
            ->where('type', TaskProgressService::TYPE_ARTIFACT_PROCESSED)
            ->get()
            ->firstOrFail(fn (BuddyTaskEvent $event) => $event->data['artifact_id'] === $artifact->id);
    }

    public function test_text_is_bounded_to_the_council_artifact_limit_and_redacted(): void
    {
        $text = "Authorization: Bearer x\n".str_repeat("line of text\n", 2000);
        $artifact = $this->process($this->artifact($text, 'text/plain'));

        $this->assertSame('completed', $artifact->processing_status);
        $this->assertSame('v1', $artifact->processor_version);
        $this->assertLessThanOrEqual(16000, mb_strlen($artifact->content));
        $this->assertGreaterThan(15000, mb_strlen($artifact->content));
        $this->assertStringNotContainsString('Bearer x', $artifact->content);
        $this->assertStringContainsString('[REDACTED_HEADER]', $artifact->content);
        $this->assertStringContainsString('line of text', $artifact->content);

        $event = $this->processedEvent($artifact);
        $this->assertSame('completed', $event->data['processing_status']);
        $this->assertSame('v1', $event->data['processor_version']);
        $this->assertSame($artifact->sha256, $event->data['content_hash']);
        $this->assertNull($event->data['error']);
        $this->assertArrayNotHasKey('content', $event->data);
    }

    public function test_json_credential_fields_are_redacted(): void
    {
        $json = (string) json_encode(['api_key' => 'sk-live-1234567890', 'level' => 'error', 'message' => 'boom']);
        $artifact = $this->process($this->artifact($json, 'application/json'));

        $this->assertSame('completed', $artifact->processing_status);
        $this->assertStringNotContainsString('sk-live-1234567890', $artifact->content);
        $this->assertStringContainsString('"credential":"[REDACTED]"', $artifact->content);
        $this->assertStringContainsString('"message":"boom"', $artifact->content);
    }

    public function test_images_get_a_one_line_summary_without_extraction(): void
    {
        $png = "\x89PNG\r\n\x1a\n".random_bytes(100);
        $artifact = $this->process($this->artifact($png, 'image/png'));

        $this->assertSame('completed', $artifact->processing_status);
        $this->assertSame('image/png, 108 bytes', $artifact->content);

        $jpeg = $this->process($this->artifact("\xFF\xD8\xFF".random_bytes(20), 'image/jpeg'));
        $this->assertSame('image/jpeg, 23 bytes', $jpeg->content);
    }

    public function test_gzip_traces_are_expanded_under_a_bound_and_redacted(): void
    {
        // Real traces vary line to line; a fixture that compresses past the
        // ratio ceiling would be rejected as a bomb, which is its own test.
        $lines = array_map(fn (int $i): string => sprintf('trace %d %s', $i, bin2hex(random_bytes(12))), range(1, 1500));
        $bytes = (string) gzencode("trace line\nAuthorization: Bearer abc\napi_key=sk-live-abcdef\n".implode("\n", $lines)."\n");
        $artifact = $this->process($this->artifact($bytes, 'application/gzip'));

        $this->assertSame('completed', $artifact->processing_status);
        $this->assertStringContainsString('trace line', $artifact->content);
        $this->assertStringNotContainsString('Bearer abc', $artifact->content);
        $this->assertStringNotContainsString('sk-live-abcdef', $artifact->content);
        $this->assertLessThanOrEqual(16000, mb_strlen($artifact->content));
    }

    public function test_gzip_binary_payloads_are_summarised_not_extracted(): void
    {
        $bytes = (string) gzencode("\x00\x01\x02\x03binary");
        $artifact = $this->process($this->artifact($bytes, 'application/gzip'));

        $this->assertSame('completed', $artifact->processing_status);
        $this->assertSame(sprintf('application/gzip, %d bytes, expanded 10 bytes, binary payload', strlen($bytes)), $artifact->content);
    }

    public function test_gzip_bombs_are_rejected_visibly(): void
    {
        $bytes = (string) gzencode(str_repeat("\0", 1048576));
        $this->assertLessThan(10000, strlen($bytes));

        $artifact = $this->process($this->artifact($bytes, 'application/gzip'));

        $this->assertSame('failed', $artifact->processing_status);
        $this->assertSame('v1', $artifact->processor_version);
        $this->assertSame('[processing failed: archive_expansion_limit]', $artifact->content);
        $this->assertSame('archive_expansion_limit', $this->processedEvent($artifact)->data['error']);
    }

    public function test_zip_archives_list_entries_and_extract_text(): void
    {
        $bytes = $this->zip([
            'logs/app.log' => "hello\nAuthorization: Bearer zzz\n",
            'meta.json' => '{"token":"abc123","ok":true}',
            'bin.dat' => "\x00\x01\x02",
        ]);
        $artifact = $this->process($this->artifact($bytes, 'application/zip'));

        $this->assertSame('completed', $artifact->processing_status);
        $this->assertStringContainsString('== logs/app.log (32 bytes) ==', $artifact->content);
        $this->assertStringContainsString('hello', $artifact->content);
        $this->assertStringNotContainsString('Bearer zzz', $artifact->content);
        $this->assertStringNotContainsString('abc123', $artifact->content);
        $this->assertStringContainsString('"ok":true', $artifact->content);
        $this->assertStringContainsString("== bin.dat (3 bytes) ==\n[binary entry]", $artifact->content);
    }

    public function test_zip_bombs_and_encrypted_archives_are_rejected(): void
    {
        $bomb = $this->zip(['zeros.bin' => str_repeat('0', 2097152)]);
        $this->assertLessThan(20000, strlen($bomb));
        $artifact = $this->process($this->artifact($bomb, 'application/zip'));

        $this->assertSame('failed', $artifact->processing_status);
        $this->assertSame('[processing failed: archive_expansion_limit]', $artifact->content);

        if (! method_exists(ZipArchive::class, 'setEncryptionName')) {
            $this->markTestSkipped('libzip without encryption support');
        }

        $encrypted = $this->process($this->artifact($this->zip(['secret.txt' => 'hi'], 'pw'), 'application/zip'));
        $this->assertSame('failed', $encrypted->processing_status);
        $this->assertSame('[processing failed: archive_encrypted]', $encrypted->content);
    }

    public function test_corrupt_archives_and_missing_objects_fail_visibly_without_failing_the_task(): void
    {
        $corrupt = $this->process($this->artifact("\x1F\x8B\x08garbage-garbage-garbage", 'application/gzip'));
        $this->assertSame('failed', $corrupt->processing_status);
        $this->assertSame('[processing failed: archive_invalid]', $corrupt->content);

        $notZip = $this->process($this->artifact("PK\x03\x04not really a zip", 'application/zip'));
        $this->assertSame('failed', $notZip->processing_status);
        $this->assertSame('[processing failed: archive_invalid]', $notZip->content);

        $missing = $this->process($this->artifact('gone', 'text/plain', storeObject: false));
        $this->assertSame('failed', $missing->processing_status);
        $this->assertSame('[processing failed: object_missing]', $missing->content);
        $this->assertSame('object_missing', $this->processedEvent($missing)->data['error']);

        $this->assertSame('pending', $this->task->fresh()->status->value);
    }

    public function test_exhausted_tries_leave_a_visible_failure(): void
    {
        $artifact = $this->artifact('hello', 'text/plain');

        (new ProcessArtifactJob($artifact->id, (string) $artifact->sha256))->failed(new RuntimeException('worker died'));

        $fresh = $artifact->fresh();
        $this->assertSame('failed', $fresh->processing_status);
        $this->assertSame('v1', $fresh->processor_version);
        $this->assertSame('[processing failed: job_failed]', $fresh->content);
        $this->assertSame('job_failed', $this->processedEvent($artifact)->data['error']);

        // Settled means settled: a late handle() for the same key is a no-op.
        $this->assertSame('[processing failed: job_failed]', $this->process($artifact)->content);
    }

    public function test_a_repeat_for_the_same_logical_key_is_a_no_op(): void
    {
        $artifact = $this->process($this->artifact('first version', 'text/plain'));
        $this->assertSame('first version', $artifact->content);

        $this->store->put((string) $artifact->object_key, 'second version');
        $again = $this->process($artifact);

        $this->assertSame('first version', $again->content);
        $this->assertSame(1, BuddyTaskEvent::where('type', TaskProgressService::TYPE_ARTIFACT_PROCESSED)->count());

        // A different hash is a different logical key and is ignored for this row.
        app()->call([new ProcessArtifactJob($artifact->id, hash('sha256', 'second version')), 'handle']);
        $this->assertSame('first version', $artifact->fresh()->content);
    }

    public function test_deleted_and_legacy_artifacts_are_never_processed(): void
    {
        $deleted = $this->artifact('secret text', 'text/plain');
        $deleted->forceFill([
            'deleted_at' => now(),
            'storage_status' => 'deleted',
            'content' => ArtifactStorageService::DELETED_CONTENT,
        ])->save();

        $this->assertSame(ArtifactStorageService::DELETED_CONTENT, $this->process($deleted)->content);
        $this->assertSame('pending', $deleted->fresh()->processing_status);

        $legacy = BuddyArtifact::create(['buddy_task_id' => $this->task->id, 'type' => 'log', 'content' => 'inline']);
        app()->call([new ProcessArtifactJob($legacy->id, hash('sha256', 'inline')), 'handle']);

        $this->assertSame('inline', $legacy->fresh()->content);
        $this->assertNull($legacy->fresh()->processing_status);
        $this->assertSame(0, BuddyTaskEvent::count());
    }

    public function test_the_job_carries_bounds_and_a_unique_key(): void
    {
        $job = new ProcessArtifactJob(7, 'abc');

        $this->assertSame('7:abc:v1', $job->uniqueId());
        $this->assertSame(2, (new \ReflectionClass($job))->getAttributes(Tries::class)[0]->newInstance()->tries);
        $this->assertSame(120, (new \ReflectionClass($job))->getAttributes(Timeout::class)[0]->newInstance()->timeout);
    }
}
