<?php

namespace Tests\Feature;

use App\Models\BuddyRun;
use App\Models\BuddyTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QueueReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_wait_runtime_and_concurrency_inside_the_window(): void
    {
        $t0 = CarbonImmutable::parse('2026-09-15T10:00:00Z');
        CarbonImmutable::setTestNow($t0->addHour());

        $a = BuddyTask::factory()->create(['operation' => 'evaluate', 'created_at' => $t0, 'queued_at' => $t0, 'worker_started_at' => $t0->addSeconds(4)]);
        $b = BuddyTask::factory()->create(['operation' => 'evaluate', 'created_at' => $t0->addSeconds(5), 'queued_at' => $t0->addSeconds(5), 'worker_started_at' => $t0->addSeconds(65)]);
        BuddyTask::factory()->create(['operation' => 'council', 'created_at' => $t0->addSeconds(10)]);
        $d = BuddyTask::factory()->create(['operation' => 'evaluate', 'created_at' => $t0->addSeconds(20), 'queued_at' => $t0->addSeconds(20)]);
        BuddyTask::factory()->create(['operation' => 'evaluate', 'created_at' => $t0->subDays(2), 'queued_at' => $t0->subDays(2), 'worker_started_at' => $t0->subDays(2)->addSeconds(900)]);

        $this->recordRun($a, 'completed', $t0->addSeconds(4), $t0->addSeconds(64));
        $this->recordRun($a, 'failed', $t0->addSeconds(30), $t0->addSeconds(90));
        $this->recordRun($b, 'completed', $t0->addSeconds(65), $t0->addSeconds(125));
        $this->recordRun($d, 'started', $t0->addSeconds(70), null);

        $this->assertSame(0, Artisan::call('buddy:queue:report', ['--since' => $t0->toISOString(), '--until' => $t0->addHour()->toISOString(), '--json' => true]));
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(['n' => 3, 'untimed' => 0, 'waiting' => 1], array_intersect_key($report['tasks']['evaluate'], array_flip(['n', 'untimed', 'waiting'])));
        $this->assertSame(['n' => 2, 'p50' => 4, 'p90' => 4, 'p95' => 4, 'max' => 60], $report['tasks']['evaluate']['queue_wait_s']);
        $this->assertSame(1, $report['tasks']['council']['untimed']);

        $this->assertSame(['n' => 4, 'open' => 1, 'failed' => 1], array_intersect_key($report['runs']['evaluation'], array_flip(['n', 'open', 'failed'])));
        $this->assertSame(['n' => 3, 'p50' => 60, 'p90' => 60, 'p95' => 60, 'max' => 60], $report['runs']['evaluation']['runtime_s']);
        $this->assertSame(['n' => 4, 'max_concurrent' => 3, 'started_within_60s_of_previous' => 3], $report['runs']['all']);
    }

    public function test_it_accepts_durations_and_rejects_nonsense(): void
    {
        $this->assertSame(0, Artisan::call('buddy:queue:report', ['--since' => '7d']));
        $this->assertStringContainsString('max concurrent 0', Artisan::output());

        $this->assertSame(1, Artisan::call('buddy:queue:report', ['--since' => 'yesterday-ish']));
    }

    private function recordRun(BuddyTask $task, string $status, CarbonImmutable $started, ?CarbonImmutable $completed): void
    {
        BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => $task->runs()->count() + 1,
            'run_type' => 'evaluation',
            'status' => $status,
            'started_at' => $started,
            'completed_at' => $completed,
        ]);
    }
}
