<?php

namespace App\Services\Interventions;

use App\Contracts\MemoryGateway;
use App\Services\Council\CouncilProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

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
        $checks['timeout_configuration'] = $timeouts['provider'] < $timeouts['job']
            && $timeouts['job'] < $timeouts['worker']
            && $timeouts['council_job'] < $timeouts['worker']
            && $timeouts['worker'] < $timeouts['retry_after'];

        $providers = [];
        foreach (config('buddy_agents.council.profiles') as $name) {
            $profile = CouncilProfile::resolve($name);
            $providers[$name] = [
                'configured' => (bool) config($profile['credential']) && (bool) filter_var($profile['base_url'], FILTER_VALIDATE_URL),
                'availability' => 'not_probed',
            ];
        }

        return [
            'health' => in_array(false, $checks, true) ? 'degraded' : 'ready',
            'checks' => $checks,
            'council_providers' => $providers,
            'worker_process' => 'not_inspected; timeout check covers application configuration only',
        ];
    }
}
