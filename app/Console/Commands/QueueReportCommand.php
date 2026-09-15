<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/*
 * Latency evidence for the queue harness (ADR 0014). It reads the timestamps
 * the task state machine records (queued_at when a submission is accepted,
 * worker_started_at when a worker claims it) and the run intervals, so queue
 * wait, model runtime and concurrency are reported separately: the harness
 * can only change the first and the third. Tasks accepted before those
 * columns existed are counted, never guessed. Runs still open at the end of
 * the window are censored: they count towards concurrency, not runtime.
 */
class QueueReportCommand extends Command
{
    protected $signature = 'buddy:queue:report
        {--since=24h : Window start as a duration (90m, 24h, 7d) or an ISO-8601 timestamp}
        {--until= : Window end as an ISO-8601 timestamp; defaults to now}
        {--json : Print the report as JSON}';

    protected $description = 'Report queue wait, runtime and concurrency for the tasks and runs in a window';

    public function handle(): int
    {
        $until = $this->parse((string) $this->option('until')) ?? CarbonImmutable::now();
        $since = $this->parse((string) $this->option('since'), $until);

        if ($since === null || $since->gte($until)) {
            $this->error('Give --since as a duration (90m, 24h, 7d) or an ISO-8601 timestamp before --until.');

            return self::FAILURE;
        }

        $report = [
            'window' => ['since' => $since->toISOString(), 'until' => $until->toISOString()],
            'tasks' => $this->tasks($since, $until),
            'runs' => $this->runs($since, $until),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function tasks(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $tasks = BuddyTask::query()
            ->whereBetween('created_at', [$since, $until])
            ->get(['operation', 'status', 'queued_at', 'worker_started_at']);

        return $tasks->groupBy('operation')->map(function (Collection $group) {
            $waits = $group
                ->filter(fn (BuddyTask $task) => $task->queued_at !== null && $task->worker_started_at !== null)
                ->map(fn (BuddyTask $task) => max(0, $task->worker_started_at->getTimestamp() - $task->queued_at->getTimestamp()))
                ->values();

            return [
                'n' => $group->count(),
                'untimed' => $group->filter(fn (BuddyTask $task) => $task->queued_at === null)->count(),
                'waiting' => $group->filter(fn (BuddyTask $task) => $task->queued_at !== null && $task->worker_started_at === null)->count(),
                'queue_wait_s' => $this->percentiles($waits),
            ];
        })->sortKeys()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function runs(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $runs = BuddyRun::query()
            ->whereBetween('started_at', [$since, $until])
            ->orderBy('started_at')
            ->get(['run_type', 'status', 'started_at', 'completed_at']);

        $byType = $runs->groupBy('run_type')->map(function (Collection $group) {
            $durations = $group
                ->filter(fn (BuddyRun $run) => $run->completed_at !== null)
                ->map(fn (BuddyRun $run) => max(0, $run->completed_at->getTimestamp() - $run->started_at->getTimestamp()))
                ->values();

            return [
                'n' => $group->count(),
                'open' => $group->filter(fn (BuddyRun $run) => $run->completed_at === null)->count(),
                'failed' => $group->filter(fn (BuddyRun $run) => $run->status === RunStatus::Failed)->count(),
                'runtime_s' => $this->percentiles($durations),
            ];
        })->sortKeys()->all();

        return $byType + ['all' => $this->concurrency($runs, $until)];
    }

    /**
     * Intervals are closed at the window end for open runs. Ends sort before
     * starts at equal timestamps so back-to-back runs do not count as overlap.
     *
     * @param  Collection<int, BuddyRun>  $runs
     * @return array{n: int, max_concurrent: int, started_within_60s_of_previous: int}
     */
    protected function concurrency(Collection $runs, CarbonImmutable $until): array
    {
        $events = [];
        $bursts = 0;
        $previousStart = null;

        foreach ($runs as $run) {
            $start = $run->started_at->getTimestamp();
            $end = max($start, ($run->completed_at ?? $until)->getTimestamp());
            $events[] = [$start, 1];
            $events[] = [$end, -1];

            if ($previousStart !== null && $start - $previousStart <= 60) {
                $bursts++;
            }

            $previousStart = $start;
        }

        usort($events, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $current = 0;
        $max = 0;

        foreach ($events as [, $delta]) {
            $current += $delta;
            $max = max($max, $current);
        }

        return [
            'n' => $runs->count(),
            'max_concurrent' => $max,
            'started_within_60s_of_previous' => $bursts,
        ];
    }

    /**
     * @param  Collection<int, int>  $values
     * @return array{n: int, p50: int|null, p90: int|null, p95: int|null, max: int|null}
     */
    protected function percentiles(Collection $values): array
    {
        $sorted = $values->sort()->values()->all();
        $count = count($sorted);
        $at = fn (int $percent): ?int => $count === 0 ? null : $sorted[(int) floor($percent / 100 * ($count - 1))];

        return [
            'n' => $count,
            'p50' => $at(50),
            'p90' => $at(90),
            'p95' => $at(95),
            'max' => $count === 0 ? null : $sorted[$count - 1],
        ];
    }

    protected function parse(string $value, ?CarbonImmutable $relativeTo = null): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d+)([mhd])$/', $value, $match) === 1) {
            $base = $relativeTo ?? CarbonImmutable::now();

            return match ($match[2]) {
                'm' => $base->subMinutes((int) $match[1]),
                'h' => $base->subHours((int) $match[1]),
                default => $base->subDays((int) $match[1]),
            };
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function render(array $report): void
    {
        $this->line(sprintf('Window %s .. %s', $report['window']['since'], $report['window']['until']));

        $this->table(
            ['operation', 'tasks', 'untimed', 'waiting', 'wait n', 'p50', 'p90', 'p95', 'max'],
            collect($report['tasks'])->map(fn (array $row, string $operation) => [
                $operation, $row['n'], $row['untimed'], $row['waiting'],
                $row['queue_wait_s']['n'], $row['queue_wait_s']['p50'], $row['queue_wait_s']['p90'], $row['queue_wait_s']['p95'], $row['queue_wait_s']['max'],
            ])->values()->all(),
        );

        $this->table(
            ['run type', 'runs', 'open', 'failed', 'runtime n', 'p50', 'p90', 'p95', 'max'],
            collect($report['runs'])->except('all')->map(fn (array $row, string $type) => [
                $type, $row['n'], $row['open'], $row['failed'],
                $row['runtime_s']['n'], $row['runtime_s']['p50'], $row['runtime_s']['p90'], $row['runtime_s']['p95'], $row['runtime_s']['max'],
            ])->values()->all(),
        );

        $all = $report['runs']['all'];
        $this->line(sprintf('Runs %d, max concurrent %d, started within 60s of the previous run %d.', $all['n'], $all['max_concurrent'], $all['started_within_60s_of_previous']));
    }
}
