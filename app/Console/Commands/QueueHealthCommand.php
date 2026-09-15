<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\BuddyRun;
use App\Services\Queue\LaneGauge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/*
 * Lane-level health check run every fifteen minutes by caj-buddy-queue-health
 * (ADR 0014). It logs the BUDDY_QUEUE_DEGRADED marker the Azure scheduled-query
 * alert watches when an accepted task has waited longer than a worker pickup
 * should ever take, or when evaluations keep failing. Thresholds are
 * deliberately coarse: this catches a wedged lane, not a slow minute.
 */
class QueueHealthCommand extends Command
{
    protected $signature = 'buddy:queue:health
        {--max-wait=300 : Seconds an accepted task may wait for a worker before the lane counts as degraded}
        {--max-failed=3 : Failed evaluation runs tolerated inside the window}
        {--window=60 : Trailing window in minutes for failed runs}';

    protected $description = 'Check lane wait times and failure counts; logs BUDDY_QUEUE_DEGRADED when a threshold is breached';

    public function handle(LaneGauge $gauge): int
    {
        $maxWait = max(1, (int) $this->option('max-wait'));
        $maxFailed = max(0, (int) $this->option('max-failed'));
        $window = max(1, (int) $this->option('window'));

        $waiting = $gauge->waiting();
        $lanes = $gauge->lanes();
        $failed = BuddyRun::query()
            ->where('run_type', 'evaluation')
            ->where('status', RunStatus::Failed)
            ->where('started_at', '>=', now()->subMinutes($window))
            ->count();

        $breaches = [];

        foreach ($waiting as $operation => $stats) {
            if ($stats['oldest_wait_s'] > $maxWait) {
                $breaches[] = "{$operation} waiting {$stats['oldest_wait_s']}s (max {$maxWait}s)";
            }
        }

        if ($failed > $maxFailed) {
            $breaches[] = "{$failed} failed evaluations in {$window}m (max {$maxFailed})";
        }

        $metrics = [
            'waiting' => $waiting,
            'failed_evaluations' => $failed,
            'window_minutes' => $window,
            'lanes' => collect($lanes)->map(fn (array $lane) => ['pending' => $lane['pending'], 'reserved' => $lane['reserved'], 'delayed' => $lane['delayed']])->all(),
        ];

        if ($breaches !== []) {
            Log::error('BUDDY_QUEUE_DEGRADED', $metrics + ['breaches' => $breaches]);
            $this->error('Degraded: '.implode('; ', $breaches).' '.json_encode($metrics));

            return self::FAILURE;
        }

        $this->info('Healthy: '.json_encode($metrics));

        return self::SUCCESS;
    }
}
