<?php

namespace Tests\Feature;

use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Services\Queue\LaneGauge;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class QueueHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_healthy_when_tasks_are_claimed_promptly(): void
    {
        $now = CarbonImmutable::parse('2026-09-15T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        BuddyTask::factory()->create(['operation' => 'evaluate', 'queued_at' => $now->subSeconds(20), 'worker_started_at' => $now->subSeconds(18)]);
        BuddyTask::factory()->create(['operation' => 'evaluate', 'queued_at' => $now->subSeconds(30)]);

        Log::spy();

        $this->artisan('buddy:queue:health')->assertSuccessful();
        Log::shouldNotHaveReceived('error');

        $waiting = app(LaneGauge::class)->waiting();
        $this->assertSame(['evaluate' => ['count' => 1, 'oldest_wait_s' => 30]], $waiting);
    }

    public function test_it_logs_the_marker_when_a_task_waits_too_long_or_evaluations_keep_failing(): void
    {
        $now = CarbonImmutable::parse('2026-09-15T12:00:00Z');
        CarbonImmutable::setTestNow($now);
        $stuck = BuddyTask::factory()->create(['operation' => 'council', 'queued_at' => $now->subMinutes(10)]);
        BuddyTask::factory()->completed()->create(['operation' => 'evaluate', 'queued_at' => $now->subHours(3)]);

        Log::spy();

        $this->artisan('buddy:queue:health', ['--max-wait' => 300])->assertFailed();
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'BUDDY_QUEUE_DEGRADED'
            && $context['waiting']['council']['oldest_wait_s'] === 600
            && str_contains($context['breaches'][0], 'council waiting 600s'))->once();

        $stuck->worker_started_at = $now;
        $stuck->save();

        foreach (range(1, 4) as $n) {
            BuddyRun::create(['buddy_task_id' => $stuck->id, 'run_number' => $n, 'run_type' => 'evaluation', 'status' => 'failed', 'started_at' => $now->subMinutes(5), 'completed_at' => $now->subMinutes(4)]);
        }

        $this->artisan('buddy:queue:health', ['--max-failed' => 3, '--window' => 60])->assertFailed();
        $this->artisan('buddy:queue:health', ['--max-failed' => 3, '--window' => 2])->assertSuccessful();
    }
}
