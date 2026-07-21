<?php

namespace App\Console\Commands;

use App\Models\EvaluationSuite;
use App\Services\Cil\LangSmithEvaluationService;
use Illuminate\Console\Command;

class CilSyncSuitesCommand extends Command
{
    protected $signature = 'buddy:cil-sync-suites';

    protected $description = 'Sync CIL evaluation suites to LangSmith datasets';

    public function handle(LangSmithEvaluationService $langsmith): int
    {
        if (! $langsmith->enabled()) {
            $this->error('LANGSMITH_API_KEY is not configured.');

            return self::FAILURE;
        }

        $suites = EvaluationSuite::all();

        if ($suites->isEmpty()) {
            $this->info('No evaluation suites to sync.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($suites as $suite) {
            $alreadySynced = $suite->langsmith_dataset_id !== null;
            $datasetId = $langsmith->syncSuite($suite);

            $rows[] = [
                $suite->name,
                $suite->kind,
                $suite->frozen ? 'yes' : 'no',
                count($suite->cases),
                $datasetId ?? 'FAILED',
                $alreadySynced ? 'already synced' : ($datasetId !== null ? 'synced' : 'error'),
            ];
        }

        $this->table(['Suite', 'Kind', 'Frozen', 'Cases', 'Dataset ID', 'Status'], $rows);

        return self::SUCCESS;
    }
}
