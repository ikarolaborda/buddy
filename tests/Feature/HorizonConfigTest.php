<?php

namespace Tests\Feature;

use App\Jobs\DeliverOutboxRemoteJob;
use App\Jobs\DispatchDiagnosticCaptureJob;
use App\Jobs\PrefetchEcosystemKnowledgeJob;
use App\Jobs\ProcessArtifactJob;
use App\Jobs\PurgeArtifactObjectJob;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use ReflectionClass;
use Tests\TestCase;

/*
 * config/horizon.php repeats the lane names and capacities of
 * config/buddy.php through the same environment variables. These tests are
 * the only thing keeping the two in step, and they pin the timeout ordering
 * provider < job < supervisor < retry_after that keeps a running job from
 * being redelivered or killed by the process manager.
 */
class HorizonConfigTest extends TestCase
{
    public function test_each_lane_has_a_fixed_pool_matching_the_configured_capacity(): void
    {
        $plan = ProvisioningPlan::get('test')->toSupervisorOptions();

        $this->assertArrayHasKey('*', $plan, 'supervisors must apply to every environment');
        $this->assertTrue(Str::is('*', 'production'));

        foreach (config('buddy.queues.lanes') as $lane => $queue) {
            $options = $plan['*'][$lane] ?? null;

            $this->assertNotNull($options, "no Horizon supervisor for lane {$lane}");
            $this->assertSame($queue, $options->queue);
            $this->assertSame('redis', $options->connection);
            $this->assertSame(config("buddy.queues.capacity.{$lane}"), $options->maxProcesses);
            $this->assertSame($options->maxProcesses, $options->minProcesses, "{$lane} pool must be fixed-size");
            $this->assertSame('simple', $options->balance);
        }
    }

    public function test_supervisor_timeouts_keep_the_ordering_with_a_margin(): void
    {
        $plan = ProvisioningPlan::get('test')->toSupervisorOptions()['*'];
        $timeouts = config('buddy.timeouts');

        $this->assertGreaterThan($timeouts['job'], $plan['evaluations']->timeout);
        $this->assertGreaterThan($timeouts['council_job'], $plan['council']->timeout);
        $this->assertGreaterThanOrEqual($plan['council']->timeout, $plan['legacy']->timeout, 'legacy default may still hold a council');

        foreach ($plan as $lane => $options) {
            $this->assertLessThan($timeouts['retry_after'], $options->timeout, "{$lane} supervisor timeout must stay under retry_after");
        }

        foreach ([DeliverOutboxRemoteJob::class, ProcessArtifactJob::class, PurgeArtifactObjectJob::class, DispatchDiagnosticCaptureJob::class, PrefetchEcosystemKnowledgeJob::class] as $job) {
            $attribute = (new ReflectionClass($job))->getAttributes(Timeout::class)[0]->newInstance();

            $this->assertLessThan($plan['fast']->timeout, $attribute->timeout, "{$job} timeout must fit the fast lane");
        }
    }

    public function test_bookkeeping_is_trimmed_and_the_dashboard_is_closed(): void
    {
        $this->assertLessThanOrEqual(60, config('horizon.trim.recent'));
        $this->assertLessThanOrEqual(60, config('horizon.trim.completed'));
        $this->assertStringStartsWith('buddy-', config('horizon.prefix'));
        $this->assertFalse(config('horizon.fast_termination'));

        $this->get('/horizon')->assertForbidden();
    }
}
