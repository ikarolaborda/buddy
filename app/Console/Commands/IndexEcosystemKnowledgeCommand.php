<?php

namespace App\Console\Commands;

use App\Services\Knowledge\Indexing\AlgoliaKnowledgeIndexPublisher;
use App\Services\Knowledge\Indexing\EcosystemKnowledgeIndexBuilder;
use App\Services\Knowledge\Indexing\KnowledgeIndexManifestWriter;
use Illuminate\Console\Command;

final class IndexEcosystemKnowledgeCommand extends Command
{
    protected $signature = 'buddy:index-ecosystem-knowledge
        {product : aerolambda, buddy, theravista, falinha, or ritmovida}
        {sources* : Repository sources as repository=/absolute/or/relative/path}
        {--environment= : Index prefix; defaults to BUDDY_ALGOLIA_INDEX_ENV}
        {--revision= : Override the Git revision for every source}
        {--dry-run : Extract and validate without writing to Algolia}
        {--output= : Optional JSON output path for the extracted records}
        {--allow-empty : Explicitly allow replacing an index with no records}';

    protected $description = 'Atomically index sanitized ecosystem repository knowledge into Algolia';

    public function __construct(
        private readonly EcosystemKnowledgeIndexBuilder $builder,
        private readonly AlgoliaKnowledgeIndexPublisher $publisher,
        private readonly KnowledgeIndexManifestWriter $manifestWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $index = $this->builder->build(
                product: (string) $this->argument('product'),
                sourceArguments: (array) $this->argument('sources'),
                environment: $this->option('environment') ? (string) $this->option('environment') : null,
                revisionOverride: $this->option('revision') ? (string) $this->option('revision') : null,
            );

            if ($this->option('output')) {
                $this->manifestWriter->write($index, (string) $this->option('output'));
            }

            if ($index->isEmpty() && ! $this->option('allow-empty')) {
                $this->error('Extraction returned no records; refusing to replace the index.');

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->info(sprintf('Dry run: extracted %d record(s) for %s.', $index->count(), $index->name));

                return self::SUCCESS;
            }

            $this->publisher->publish($index);
            $this->info(sprintf('Atomically indexed %d record(s) into %s.', $index->count(), $index->name));

            return self::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
