<?php

namespace App\Services\Interventions;

use App\Contracts\MemoryGateway;
use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use App\Services\Council\CouncilProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class ServiceDiagnostics
{
    public function inspect(): array
    {
        $checks = [];
        foreach ([
            'database' => fn () => DB::select('select 1') !== [],
            'queue' => fn () => Queue::connection()->size() >= 0,
            'memory' => fn () => app(MemoryGateway::class)->health()->healthy,
        ] as $name => $probe) {
            try {
                $checks[$name] = (bool) $probe();
            } catch (\Throwable) {
                $checks[$name] = false;
            }
        }

        $timeouts = config('buddy.timeouts');
        $queueRetryAfter = config('queue.connections.'.config('queue.default').'.retry_after');
        $checks['timeout_configuration'] = $timeouts['provider'] < $timeouts['job']
            && $timeouts['job'] < $timeouts['worker']
            && $timeouts['council_job'] < $timeouts['worker']
            && $timeouts['worker'] < $timeouts['retry_after']
            && is_numeric($queueRetryAfter)
            && $timeouts['worker'] < (int) $queueRetryAfter;

        $providers = [];
        foreach (config('buddy_agents.council.profiles') as $name) {
            try {
                CouncilProfile::requireConfigured($name);
                $configured = true;
            } catch (ValidationException) {
                $configured = false;
            }
            $providers[$name] = [
                'configured' => $configured,
                'availability' => 'not_probed',
            ];
        }

        return [
            'health' => in_array(false, $checks, true) ? 'degraded' : 'ready',
            'checks' => $checks,
            'council_providers' => $providers,
            'worker_process' => 'not_inspected; timeout check covers application configuration only',
            'observations' => $this->observe(),
        ];
    }

    /*
     * Worker-process facts read from task rows only (plan §8). A worker is
     * legitimately silent during a model call, so an old heartbeat is reported
     * as a stale observation and never folded into the health verdict above;
     * the supervisor, not this endpoint, decides what to do with it. Telemetry
     * that cannot be read is reported as unavailable rather than as healthy.
     *
     * @return array{worker_heartbeat_at: string|null, worker_heartbeat_age_seconds: int|null, oldest_queued_age_seconds: int|null, evaluating_count: int, queued_count: int, observation_state: string}
     */
    private function observe(): array
    {
        try {
            $evaluating = fn () => BuddyTask::query()->where('status', TaskStatus::Evaluating->value);

            $heartbeat = $evaluating()
                ->whereNotNull('claimed_by')
                ->whereNotNull('heartbeat_at')
                ->orderByDesc('heartbeat_at')
                ->first(['heartbeat_at'])
                ?->heartbeat_at;
            $oldestQueued = $evaluating()
                ->whereNull('claimed_by')
                ->whereNotNull('queued_at')
                ->orderBy('queued_at')
                ->first(['queued_at'])
                ?->queued_at;
            $evaluatingCount = $evaluating()->count();
            $queuedCount = $evaluating()->whereNull('claimed_by')->count();
        } catch (\Throwable) {
            return [
                'worker_heartbeat_at' => null,
                'worker_heartbeat_age_seconds' => null,
                'oldest_queued_age_seconds' => null,
                'evaluating_count' => 0,
                'queued_count' => 0,
                'observation_state' => 'unavailable',
            ];
        }

        $heartbeatAge = $heartbeat === null ? null : max(0, now()->getTimestamp() - $heartbeat->getTimestamp());
        $queuedAge = $oldestQueued === null ? null : max(0, now()->getTimestamp() - $oldestQueued->getTimestamp());

        // A heartbeat only exists on a claimed evaluating task, so an old one
        // means a worker went quiet while it still owned work.
        $stale = $heartbeatAge !== null && $heartbeatAge > (int) config('buddy.timeouts.lease', 300);

        return [
            'worker_heartbeat_at' => $heartbeat?->toISOString(),
            'worker_heartbeat_age_seconds' => $heartbeatAge,
            'oldest_queued_age_seconds' => $queuedAge,
            'evaluating_count' => $evaluatingCount,
            'queued_count' => $queuedCount,
            'observation_state' => $stale ? 'stale' : 'healthy',
        ];
    }
}
