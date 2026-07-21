<?php

namespace App\Console\Commands;

use App\Models\ImprovementCandidate;
use App\Models\PromotionDecision;
use Illuminate\Console\Command;

class CilDecideCommand extends Command
{
    protected $signature = 'buddy:cil-decide
        {candidate : Improvement candidate ID}
        {--approve : Approve the candidate}
        {--reject : Reject the candidate}
        {--by= : Decision maker}
        {--rationale= : Decision rationale}';

    protected $description = 'Record a human promotion decision for an improvement candidate';

    public function handle(): int
    {
        $candidate = ImprovementCandidate::find($this->argument('candidate'));

        if ($candidate === null) {
            $this->error('Candidate not found.');

            return self::FAILURE;
        }

        $approve = (bool) $this->option('approve');
        $reject = (bool) $this->option('reject');

        if ($approve === $reject) {
            $this->error('Pass exactly one of --approve or --reject.');

            return self::FAILURE;
        }

        $decidedBy = (string) ($this->option('by') ?? '');

        if ($decidedBy === '') {
            $this->error('--by=<name> is required: promotion decisions must be attributable.');

            return self::FAILURE;
        }

        if ($candidate->evaluationRuns()->whereNotNull('completed_at')->doesntExist()) {
            $this->error('Candidate has no completed evaluation run; replay it first (buddy:cil-replay).');

            return self::FAILURE;
        }

        PromotionDecision::create([
            'improvement_candidate_id' => $candidate->id,
            'decided_by' => $decidedBy,
            'approved' => $approve,
            'rationale' => $this->option('rationale'),
            'decided_at' => now(),
        ]);

        $candidate->update(['status' => $approve ? 'approved' : 'rejected']);

        $this->info(sprintf(
            'Candidate #%d %s by %s.',
            $candidate->id,
            $approve ? 'approved' : 'rejected',
            $decidedBy,
        ));

        if ($approve) {
            $this->line('Approval recorded. Applying the prompt change to resources/prompts and deploying remains a separate, reviewed step.');
        }

        return self::SUCCESS;
    }
}
