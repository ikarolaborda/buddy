<?php

namespace App\Console\Commands;

use App\Models\EvaluationSuite;
use Illuminate\Console\Command;

class CilImportSuiteCommand extends Command
{
    protected $signature = 'buddy:cil-import-suite {file : Path to a suite JSON file}';

    protected $description = 'Import or update a CIL evaluation suite from a checked-in JSON file';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['name'], $data['cases']) || ! is_array($data['cases'])) {
            $this->error('Suite JSON must contain "name" and a "cases" array.');

            return self::FAILURE;
        }

        $existing = EvaluationSuite::query()->where('name', $data['name'])->first();

        if ($existing !== null && $existing->frozen) {
            $this->error("Suite '{$data['name']}' is frozen; refusing to overwrite.");

            return self::FAILURE;
        }

        $suite = EvaluationSuite::updateOrCreate(
            ['name' => $data['name']],
            [
                'kind' => $data['kind'] ?? 'golden',
                'cases' => $data['cases'],
                'frozen' => (bool) ($data['frozen'] ?? false),
            ],
        );

        $this->info("Suite '{$suite->name}' imported with ".count($suite->cases).' cases.');

        return self::SUCCESS;
    }
}
