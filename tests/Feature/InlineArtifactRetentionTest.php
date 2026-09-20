<?php

namespace Tests\Feature;

use App\Jobs\PurgeArtifactObjectJob;
use App\Models\BuddyArtifact;
use App\Models\BuddyTask;
use App\Services\Artifacts\ArtifactStorageException;
use App\Services\Artifacts\ArtifactStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/*
 * Artifacts attached inline keep storage_status null, which is what tells them
 * apart from object-backed ones. The retention sweep keys on that column, so
 * until it learned about them these were the only artifacts in the system that
 * never expired - on a finished task as much as an abandoned one.
 */
class InlineArtifactRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function inlineArtifact(int $ageDays, string $content = 'stack trace'): BuddyArtifact
    {
        $task = BuddyTask::factory()->create();

        $artifact = $task->artifacts()->create([
            'type' => 'log',
            'content' => $content,
        ]);

        $artifact->forceFill(['created_at' => now()->subDays($ageDays)])->save();

        return $artifact->refresh();
    }

    public function test_an_inline_artifact_past_retention_is_forgotten(): void
    {
        config(['buddy.edge.retention.inline_artifact_days' => 30]);
        $old = $this->inlineArtifact(31, 'secrets in a log line');

        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('forgot 1 inline artifact(s)');

        $old->refresh();
        $this->assertSame(ArtifactStorageService::DELETED_CONTENT, $old->content);
        $this->assertNotNull($old->deleted_at);
        // The row survives so the task still shows what it was given.
        $this->assertSame('log', $old->type->value);
    }

    public function test_an_inline_artifact_inside_retention_is_untouched(): void
    {
        config(['buddy.edge.retention.inline_artifact_days' => 30]);
        $recent = $this->inlineArtifact(29, 'still useful');

        $this->artisan('buddy:artifacts:cleanup')->assertSuccessful();

        $recent->refresh();
        $this->assertSame('still useful', $recent->content);
        $this->assertNull($recent->deleted_at);
    }

    public function test_it_never_claims_an_artifact_that_has_an_object(): void
    {
        /*
         * pastRetention() must keep owning every artifact with storage, because
         * those need their object deleted too. Overlap would mean the inline
         * sweep tombstoning a row whose bytes are still sitting in R2.
         */
        config(['buddy.edge.retention.inline_artifact_days' => 30]);
        $stored = $this->inlineArtifact(31, 'has an object');
        $stored->forceFill([
            'storage_status' => ArtifactStorageService::STATUS_READY,
            'object_key' => 'artifacts/abc',
            'retention_until' => now()->addDays(30),
        ])->save();

        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('forgot 0 inline artifact(s)');

        $this->assertSame('has an object', $stored->refresh()->content);
    }

    public function test_forgetting_dispatches_no_purge_job(): void
    {
        // There is no object behind an inline artifact, so a purge job would
        // only go looking for a key that was never written.
        Queue::fake();
        config(['buddy.edge.retention.inline_artifact_days' => 30]);
        $this->inlineArtifact(31);

        $this->artisan('buddy:artifacts:cleanup')->assertSuccessful();

        Queue::assertNotPushed(PurgeArtifactObjectJob::class);
    }

    public function test_a_second_sweep_does_not_touch_an_already_forgotten_artifact(): void
    {
        config(['buddy.edge.retention.inline_artifact_days' => 30]);
        $old = $this->inlineArtifact(31);

        $this->artisan('buddy:artifacts:cleanup')->assertSuccessful();
        $firstDeletedAt = $old->refresh()->deleted_at;

        $this->travel(1)->hours();
        $this->artisan('buddy:artifacts:cleanup')
            ->assertSuccessful()
            ->expectsOutputToContain('forgot 0 inline artifact(s)');

        $this->assertEquals($firstDeletedAt, $old->refresh()->deleted_at);
    }

    public function test_a_dry_run_counts_without_forgetting(): void
    {
        config(['buddy.edge.retention.inline_artifact_days' => 30]);
        $old = $this->inlineArtifact(31, 'untouched by a dry run');

        $this->artisan('buddy:artifacts:cleanup', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('forgot 1 inline artifact(s)');

        $this->assertSame('untouched by a dry run', $old->refresh()->content);
        $this->assertNull($old->refresh()->deleted_at);
    }

    public function test_forget_refuses_an_artifact_that_has_storage(): void
    {
        $artifact = $this->inlineArtifact(1);
        $artifact->forceFill([
            'storage_status' => ArtifactStorageService::STATUS_READY,
            'object_key' => 'artifacts/abc',
        ])->save();

        $this->expectException(ArtifactStorageException::class);

        app(ArtifactStorageService::class)->forget($artifact->refresh());
    }
}
