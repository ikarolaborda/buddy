<?php

namespace Tests\Feature;

use App\Jobs\CouncilDeliberateJob;
use App\Jobs\DeliverOutboxRemoteJob;
use App\Jobs\DispatchDiagnosticCaptureJob;
use App\Jobs\EvaluateTaskJob;
use App\Jobs\PrefetchEcosystemKnowledgeJob;
use App\Jobs\ProcessArtifactJob;
use App\Jobs\PurgeArtifactObjectJob;
use App\Jobs\SyntheticSleepJob;
use App\Models\BuddyTask;
use App\Models\OutboxMessage;
use App\Services\Outbox\Handlers\KnowledgePrefetchHandler;
use App\Services\Outbox\Handlers\TaskSubmittedHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/*
 * ADR 0014: every queued job rides a configured lane, so a council can never
 * sit in front of an evaluation again, and the Bicep scale rule targets equal
 * the per-replica capacities the Horizon pools are sized with.
 */
class QueueLanesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_queued_job_class_is_covered_and_pinned_to_a_configured_lane(): void
    {
        $lanes = config('buddy.queues.lanes');
        $expected = $this->jobs();

        $classes = collect(glob(app_path('Jobs/*.php')))
            ->map(fn (string $path) => 'App\\Jobs\\'.basename($path, '.php'))
            ->filter(fn (string $class) => is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract())
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect(array_keys($expected))->sort()->values()->all(), $classes, 'Add the new job to the lane map in this test.');

        foreach ($expected as $class => [$lane, $factory]) {
            $this->assertSame($lanes[$lane], $factory()->queue, "{$class} must ride the {$lane} lane");
        }
    }

    public function test_lane_names_follow_configuration(): void
    {
        config(['buddy.queues.lanes.evaluations' => 'eval-x', 'buddy.queues.lanes.fast' => 'fast-x']);
        $task = BuddyTask::factory()->create();

        $this->assertSame('eval-x', (new EvaluateTaskJob($task))->queue);
        $this->assertSame('fast-x', (new DeliverOutboxRemoteJob(1))->queue);
    }

    public function test_dispatch_sites_push_onto_the_lanes(): void
    {
        Queue::fake();
        $task = BuddyTask::factory()->create(['operation' => 'evaluate']);
        $council = BuddyTask::factory()->create(['operation' => 'council']);

        (new TaskSubmittedHandler)->handle($this->message($task, 'evaluate'));
        (new TaskSubmittedHandler)->handle($this->message($council, 'council'));
        app(KnowledgePrefetchHandler::class)->handle($this->message($task, 'evaluate'));

        Queue::assertPushedOn('evaluations', EvaluateTaskJob::class);
        Queue::assertPushedOn('council', CouncilDeliberateJob::class);
        Queue::assertPushedOn('fast', PrefetchEcosystemKnowledgeJob::class);
        Queue::assertNotPushed(EvaluateTaskJob::class, fn (EvaluateTaskJob $job) => $job->queue === 'default');
    }

    public function test_synthetic_load_targets_the_requested_lane(): void
    {
        Queue::fake();

        $this->artisan('buddy:queue:synthetic', ['--count' => 2, '--seconds' => 1, '--lane' => 'council', '--confirm' => true])->assertSuccessful();
        Queue::assertPushedOn('council', SyntheticSleepJob::class);

        $this->artisan('buddy:queue:synthetic', ['--count' => 1, '--lane' => 'nope', '--confirm' => true])->assertFailed();
    }

    public function test_bicep_capacity_defaults_match_the_configuration(): void
    {
        $bicep = (string) file_get_contents(base_path('infra/azure/modules/buddy-worker.bicep'));

        foreach (['evaluations' => 'workerEvaluationCapacity', 'council' => 'workerCouncilCapacity', 'fast' => 'workerFastCapacity'] as $lane => $param) {
            $this->assertMatchesRegularExpression('/^param '.$param.' int = (\d+)/m', $bicep);
            preg_match('/^param '.$param.' int = (\d+)/m', $bicep, $match);
            $this->assertSame(config("buddy.queues.capacity.{$lane}"), (int) $match[1], "{$param} must equal buddy.queues.capacity.{$lane}");
        }

        $this->assertStringContainsString("valueLocation: 'scaling.evaluations'", $bicep);
        $this->assertStringContainsString('targetValue: string(workerEvaluationCapacity)', $bicep);
        $this->assertStringContainsString("valueLocation: 'scaling.council'", $bicep);
        $this->assertStringContainsString('targetValue: string(workerCouncilCapacity)', $bicep);
        $this->assertStringContainsString("command: ['sh', '/var/www/html/docker/production/horizon-entrypoint.sh']", $bicep);
        $this->assertStringContainsString('HORIZON_SHUTDOWN_GRACE', $bicep);
        $this->assertTrue(is_executable(base_path('docker/production/horizon-entrypoint.sh')));
        $this->assertStringContainsString('terminationGracePeriodSeconds: 600', $bicep);
        $this->assertStringNotContainsString("valueLocation: 'pending'", $bicep);
    }

    /**
     * Every queued job with the lane it must land on. A new job without an
     * entry fails the coverage test above.
     *
     * @return array<class-string, array{0: string, 1: callable(): object}>
     */
    private function jobs(): array
    {
        $task = BuddyTask::factory()->create();

        return [
            EvaluateTaskJob::class => ['evaluations', fn () => new EvaluateTaskJob($task)],
            CouncilDeliberateJob::class => ['council', fn () => new CouncilDeliberateJob($task)],
            PrefetchEcosystemKnowledgeJob::class => ['fast', fn () => new PrefetchEcosystemKnowledgeJob($task)],
            DeliverOutboxRemoteJob::class => ['fast', fn () => new DeliverOutboxRemoteJob(1)],
            ProcessArtifactJob::class => ['fast', fn () => new ProcessArtifactJob(1, str_repeat('a', 64))],
            PurgeArtifactObjectJob::class => ['fast', fn () => new PurgeArtifactObjectJob(1)],
            DispatchDiagnosticCaptureJob::class => ['fast', fn () => new DispatchDiagnosticCaptureJob('capture', 'token')],
            SyntheticSleepJob::class => ['evaluations', fn () => new SyntheticSleepJob('syn', 1, 1)],
        ];
    }

    private function message(BuddyTask $task, string $operation): OutboxMessage
    {
        $message = new OutboxMessage;
        $message->payload = ['task_ulid' => $task->ulid, 'operation' => $operation];

        return $message;
    }
}
