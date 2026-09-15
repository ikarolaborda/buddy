<?php

namespace Tests\Feature;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryHealth;
use App\Models\BuddyTask;
use App\Services\Interventions\ServiceDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServiceDiagnosticsObservationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(MemoryGateway::class)->shouldReceive('health')->andReturn(new MemoryHealth(true, 'test'));
        config([
            'buddy.timeouts.lease' => 1200,
            'queue.default' => 'sync',
            'queue.connections.sync.retry_after' => 2400,
        ]);
    }

    public function test_an_idle_service_reports_healthy_observations_alongside_existing_keys(): void
    {
        $result = app(ServiceDiagnostics::class)->inspect();

        $this->assertSame('ready', $result['health']);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('council_providers', $result);
        $this->assertArrayHasKey('worker_process', $result);
        $this->assertSame([
            'worker_heartbeat_at' => null,
            'worker_heartbeat_age_seconds' => null,
            'oldest_queued_age_seconds' => null,
            'evaluating_count' => 0,
            'queued_count' => 0,
            'observation_state' => 'healthy',
        ], $result['observations']);
    }

    public function test_heartbeat_and_queue_age_come_only_from_evaluating_tasks(): void
    {
        $this->freezeSecond();
        BuddyTask::factory()->evaluating()->create(['claimed_by' => 'worker-a', 'heartbeat_at' => now()->subSeconds(90)]);
        $newest = BuddyTask::factory()->evaluating()->create(['claimed_by' => 'worker-b', 'heartbeat_at' => now()->subSeconds(30)]);
        BuddyTask::factory()->evaluating()->create(['queued_at' => now()->subSeconds(45)]);
        BuddyTask::factory()->evaluating()->create(['queued_at' => now()->subSeconds(200)]);
        BuddyTask::factory()->create(['queued_at' => now()->subSeconds(900)]);
        BuddyTask::factory()->completed()->create(['claimed_by' => 'worker-c', 'heartbeat_at' => now()->subSeconds(5000)]);
        BuddyTask::factory()->failed()->create(['queued_at' => now()->subSeconds(7000)]);

        $observations = app(ServiceDiagnostics::class)->inspect()['observations'];

        $this->assertSame($newest->heartbeat_at->toISOString(), $observations['worker_heartbeat_at']);
        $this->assertSame(30, $observations['worker_heartbeat_age_seconds']);
        $this->assertSame(200, $observations['oldest_queued_age_seconds']);
        $this->assertSame(4, $observations['evaluating_count']);
        $this->assertSame(2, $observations['queued_count']);
        $this->assertSame('healthy', $observations['observation_state']);
    }

    public function test_a_heartbeat_older_than_the_lease_is_stale_without_degrading_health(): void
    {
        $this->freezeSecond();
        BuddyTask::factory()->evaluating()->create(['claimed_by' => 'worker-a', 'heartbeat_at' => now()->subSeconds(1200)]);

        $result = app(ServiceDiagnostics::class)->inspect();
        $this->assertSame('healthy', $result['observations']['observation_state']);

        $this->travel(1)->seconds();

        $result = app(ServiceDiagnostics::class)->inspect();
        $this->assertSame('stale', $result['observations']['observation_state']);
        $this->assertSame(1201, $result['observations']['worker_heartbeat_age_seconds']);
        $this->assertSame('ready', $result['health']);
        $this->assertArrayNotHasKey('observations', $result['checks']);
    }

    public function test_unreadable_telemetry_is_reported_as_unavailable_not_healthy(): void
    {
        Schema::rename('buddy_tasks', 'buddy_tasks_offline');

        $result = app(ServiceDiagnostics::class)->inspect();

        $this->assertSame('ready', $result['health']);
        $this->assertSame([
            'worker_heartbeat_at' => null,
            'worker_heartbeat_age_seconds' => null,
            'oldest_queued_age_seconds' => null,
            'evaluating_count' => 0,
            'queued_count' => 0,
            'observation_state' => 'unavailable',
        ], $result['observations']);
    }
}
