<?php

namespace App\Console\Commands;

use App\Models\BuddyRun;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\ImprovementCandidate;
use App\Models\PromotionDecision;
use App\Models\TaskFeedback;
use Illuminate\Console\Command;

class CilReportCommand extends Command
{
    protected $signature = 'buddy:cil-report';

    protected $description = 'Report-only view of the Controlled Improvement Loop: run quality, feedback, and candidate status';

    public function handle(): int
    {
        if (config('buddy.cil.mode') !== 'report_only') {
            $this->warn('CIL mode is '.config('buddy.cil.mode').'; this command only reports.');
        }

        $minEvidence = (int) config('buddy.cil.min_evidence_runs');
        $totalRuns = BuddyRun::count();

        $this->info('Controlled Improvement Loop — report');
        $this->table(['Metric', 'Value'], [
            ['Total runs', $totalRuns],
            ['Completed runs', BuddyRun::where('status', 'completed')->count()],
            ['Failed runs', BuddyRun::where('status', 'failed')->count()],
            ['Feedback records', TaskFeedback::count()],
            ['Improvement candidates', ImprovementCandidate::count()],
            ['Pending promotion decisions', ImprovementCandidate::where('status', 'evaluated')->count()],
            ['Human promotion decisions', PromotionDecision::count()],
            ['Evidence threshold', $minEvidence],
        ]);

        $syncedSuites = EvaluationSuite::whereNotNull('langsmith_dataset_id')->get();

        if ($syncedSuites->isNotEmpty()) {
            $this->newLine();
            $this->info('LangSmith projections');
            $this->table(
                ['Suite', 'Dataset ID', 'Experiments'],
                $syncedSuites->map(fn ($suite) => [
                    $suite->name,
                    $suite->langsmith_dataset_id,
                    EvaluationRun::where('evaluation_suite_id', $suite->id)
                        ->whereNotNull('langsmith_experiment_id')
                        ->count(),
                ])->all(),
            );
        }

        if ($totalRuns < $minEvidence) {
            $this->line("Below evidence threshold ({$totalRuns}/{$minEvidence}); no improvement cycle is warranted yet.");
        }

        $this->line('Promotion is always a human decision; candidates never modify production prompts directly.');

        return self::SUCCESS;
    }
}
