<?php

namespace App\Console\Commands;

use App\Models\TaskFeedback;
use Illuminate\Console\Command;

/*
 * Mines outcome-labeled real tasks into a reviewable suite draft (roadmap
 * Wave 3). Only resolved and not_useful are harvested: resolved confirms
 * the recorded verdict, not_useful refutes it, and partially_resolved is
 * too ambiguous to label a case with. Output is a draft file for human
 * curation - importing remains an explicit buddy:cil-import-suite step.
 */
class CilHarvestCommand extends Command
{
    protected $signature = 'buddy:cil-harvest
        {--since= : Only harvest feedback recorded on/after this date (YYYY-MM-DD)}
        {--output= : Output file path (defaults to resources/cil/harvested-<date>.json)}';

    protected $description = 'Mine outcome-labeled closed tasks into a reviewable CIL suite draft; never imports';

    public function handle(): int
    {
        $feedback = TaskFeedback::query()
            ->whereIn('outcome', ['resolved', 'not_useful'])
            ->when($this->option('since'), fn ($query, $since) => $query->where('created_at', '>=', $since))
            ->with('task')
            ->latest()
            ->get();

        $cases = [];

        foreach ($feedback as $entry) {
            $task = $entry->task;
            $recommendation = $task?->latestRecommendation();

            if ($task === null || $recommendation === null) {
                continue;
            }

            $confirmed = $entry->outcome === 'resolved';

            $cases[] = [
                'inputs' => [
                    'task_summary' => $task->task_summary,
                    'problem_type' => $task->problem_type->value,
                    'constraints' => $task->constraints ?? [],
                    'evidence' => $task->evidence ?? [],
                    'requested_outcome' => $task->requested_outcome,
                ],
                'expected' => [
                    'accepted' => $confirmed ? (bool) $recommendation->accepted : ! $recommendation->accepted,
                ],
                'provenance' => [
                    'task' => $task->ulid,
                    'outcome' => $entry->outcome,
                    'verdict_accepted' => (bool) $recommendation->accepted,
                    'labeled_at' => $entry->created_at?->toDateString(),
                ],
            ];
        }

        if ($cases === []) {
            $this->info('No harvestable tasks: need closed tasks labeled resolved or not_useful.');

            return self::SUCCESS;
        }

        $date = now()->toDateString();
        $path = (string) ($this->option('output') ?: resource_path("cil/harvested-{$date}.json"));

        file_put_contents($path, json_encode([
            'name' => "buddy-suite-harvested-{$date}",
            'kind' => 'harvested',
            'frozen' => false,
            'cases' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->info(sprintf('Harvested %d case(s) to %s.', count($cases), $path));
        $this->line('Review the expected labels (not_useful entries invert the recorded verdict), then import with: php artisan buddy:cil-import-suite '.$path);

        return self::SUCCESS;
    }
}
