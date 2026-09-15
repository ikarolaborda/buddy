<?php

namespace Tests\Feature;

use App\Jobs\SyntheticSleepJob;
use App\Models\BuddyTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScalingMetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_absent_without_a_configured_key(): void
    {
        config(['buddy.scaling.metrics_key' => null]);

        $this->getJson('/api/internal/scaling/queue-depth')->assertNotFound();
    }

    public function test_it_rejects_a_wrong_key_and_reports_pending_work_with_the_right_one(): void
    {
        config(['buddy.scaling.metrics_key' => 'scaler-secret']);

        $this->withHeaders(['X-Buddy-Scaling-Key' => 'nope'])->getJson('/api/internal/scaling/queue-depth')->assertUnauthorized();

        BuddyTask::factory()->create(['operation' => 'evaluate', 'queued_at' => now()->subSeconds(45)]);
        BuddyTask::factory()->create(['operation' => 'evaluate', 'queued_at' => now()->subSeconds(90), 'worker_started_at' => now()->subSeconds(88)]);

        Queue::shouldReceive('size')->with('evaluations')->once()->andReturn(7);
        Queue::shouldReceive('size')->with('council')->once()->andReturn(2);
        Queue::shouldReceive('size')->with('fast')->once()->andReturn(0);
        Queue::shouldReceive('size')->with('default')->once()->andReturn(1);

        $this->withHeaders(['X-Buddy-Scaling-Key' => 'scaler-secret'])
            ->getJson('/api/internal/scaling/queue-depth')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('queue', 'default')
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('lanes.evaluations.queue', 'evaluations')
            ->assertJsonPath('lanes.evaluations.pending', 7)
            ->assertJsonPath('lanes.council.demand', 2)
            ->assertJsonPath('scaling.evaluations', 8)
            ->assertJsonPath('scaling.council', 2)
            ->assertJsonPath('scaling.fast', 0)
            ->assertJsonPath('capacity.evaluations', 5)
            ->assertJsonPath('waiting.evaluate.count', 1)
            ->assertJsonPath('waiting.evaluate.oldest_wait_s', 45);
    }

    public function test_synthetic_load_command_refuses_without_confirmation_and_dispatches_bounded_jobs(): void
    {
        Queue::fake();

        $this->artisan('buddy:queue:synthetic', ['--count' => 3])->assertFailed();
        Queue::assertNothingPushed();

        $this->artisan('buddy:queue:synthetic', ['--count' => 3, '--seconds' => 5, '--confirm' => true])->assertSuccessful();
        Queue::assertPushed(SyntheticSleepJob::class, 3);
    }
}
