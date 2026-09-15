<?php

namespace Tests\Feature;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryHealth;
use App\Services\Interventions\ServiceDiagnostics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServiceDiagnosticsTest extends TestCase
{
    public function test_queue_redelivery_before_worker_timeout_is_reported_as_degraded(): void
    {
        $this->healthyDependencies();
        config(['queue.connections.redis.retry_after' => 240]);

        $result = app(ServiceDiagnostics::class)->inspect();

        $this->assertSame('degraded', $result['health']);
        $this->assertFalse($result['checks']['timeout_configuration']);
    }

    public function test_queue_with_sufficient_redelivery_budget_is_ready(): void
    {
        $this->healthyDependencies();
        config(['queue.connections.redis.retry_after' => 2400]);

        $result = app(ServiceDiagnostics::class)->inspect();

        $this->assertSame('ready', $result['health']);
        $this->assertTrue($result['checks']['timeout_configuration']);
    }

    private function healthyDependencies(): void
    {
        config(['queue.default' => 'redis']);
        DB::shouldReceive('select')->with('select 1')->once()->andReturn([(object) ['ok' => 1]]);
        Queue::shouldReceive('connection->size')->once()->andReturn(0);
        $this->mock(MemoryGateway::class)->shouldReceive('health')->once()->andReturn(new MemoryHealth(true, 'test'));
    }
}
