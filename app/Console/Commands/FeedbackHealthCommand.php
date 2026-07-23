<?php

namespace App\Console\Commands;

use App\Models\BuddyRun;
use App\Models\TaskFeedback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/*
 * In-house replacement for the plan-gated LangSmith alert: computes
 * feedback and run health over a trailing window and emits the
 * BUDDY_FEEDBACK_DEGRADED marker that the Azure scheduled-query alert
 * matches on. Buddy's own tables are the source of truth.
 */
class FeedbackHealthCommand extends Command
{
    protected $signature = 'buddy:feedback-health {--days= : Trailing window in days (default from config)}';

    protected $description = 'Check outcome-feedback and run health; logs BUDDY_FEEDBACK_DEGRADED when thresholds are breached';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('buddy.health.window_days'));
        $since = now()->subDays($days);

        $scored = TaskFeedback::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('score')
            ->get(['score', 'outcome']);

        $runsTotal = BuddyRun::query()->where('created_at', '>=', $since)->count();
        $runsFailed = BuddyRun::query()->where('created_at', '>=', $since)->where('status', 'failed')->count();

        $metrics = [
            'window_days' => $days,
            'scored_feedback' => $scored->count(),
            'mean_score' => $scored->count() > 0 ? round($scored->avg('score'), 1) : null,
            'not_useful_rate' => $scored->count() > 0
                ? round($scored->where('outcome', 'not_useful')->count() / $scored->count(), 4)
                : null,
            'runs_total' => $runsTotal,
            'failed_run_rate' => $runsTotal > 0 ? round($runsFailed / $runsTotal, 4) : null,
        ];

        $breaches = [];

        if ($scored->count() >= (int) config('buddy.health.min_samples')) {
            if ($metrics['mean_score'] < (int) config('buddy.health.min_mean_score')) {
                $breaches[] = 'mean_score';
            }

            if ($metrics['not_useful_rate'] > (float) config('buddy.health.max_not_useful_rate')) {
                $breaches[] = 'not_useful_rate';
            }
        }

        if ($runsTotal > 0 && $metrics['failed_run_rate'] > (float) config('buddy.health.max_failed_run_rate')) {
            $breaches[] = 'failed_run_rate';
        }

        if ($breaches !== []) {
            Log::error('BUDDY_FEEDBACK_DEGRADED', $metrics + ['breaches' => $breaches]);
            $this->error('Degraded: '.implode(', ', $breaches).' '.json_encode($metrics));

            return self::FAILURE;
        }

        $this->info('Healthy: '.json_encode($metrics));

        return self::SUCCESS;
    }
}
