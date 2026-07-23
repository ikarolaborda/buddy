<?php

namespace App\Console\Commands;

use App\Models\EvaluationSuite;
use App\Models\ImprovementCandidate;
use App\Services\Cil\CilReplayService;
use Illuminate\Console\Command;

class CilReplayCommand extends Command
{
    protected $signature = 'buddy:cil-replay
        {candidate : Improvement candidate ID}
        {suite : Evaluation suite name}';

    protected $description = 'Replay baseline and candidate prompts against a suite; report-only, never promotes';

    public function handle(CilReplayService $replay): int
    {
        $candidate = ImprovementCandidate::find($this->argument('candidate'));

        if ($candidate === null) {
            $this->error('Candidate not found.');

            return self::FAILURE;
        }

        $suite = EvaluationSuite::where('name', $this->argument('suite'))->first();

        if ($suite === null) {
            $this->error('Suite not found.');

            return self::FAILURE;
        }

        try {
            $run = $replay->replay($candidate, $suite);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $candidate->update(['status' => 'evaluated']);

        $this->info("Evaluation run #{$run->id} complete.");
        $this->table(['Variant', 'Accuracy', 'Graded'], [
            ['baseline', $run->baseline_metrics['accuracy'], $run->baseline_metrics['graded_score'] ?? 'n/a'],
            ['candidate', $run->candidate_metrics['accuracy'], $run->candidate_metrics['graded_score'] ?? 'n/a'],
        ]);
        $this->line('Report-only signal (passed): '.($run->passed ? 'yes' : 'no'));

        if ($run->langsmith_experiment_id !== null) {
            $this->line("LangSmith experiment: {$run->langsmith_experiment_id}");
        }

        $this->line('Promotion requires a human decision: php artisan buddy:cil-decide '.$candidate->id);

        return self::SUCCESS;
    }
}
