<?php

namespace App\Services\Knowledge\Indexing;

use App\Services\Knowledge\RepositoryKnowledgeExtractor;
use Illuminate\Support\Str;

final readonly class EcosystemKnowledgeIndexBuilder
{
    public function __construct(
        private RepositoryIndexSourceFactory $sources,
        private RepositoryKnowledgeExtractor $extractor,
    ) {}

    /**
     * @param  array<int, string>  $sourceArguments
     */
    public function build(
        string $product,
        array $sourceArguments,
        ?string $environment = null,
        ?string $revisionOverride = null,
    ): EcosystemKnowledgeIndex {
        $product = strtolower($product);
        $products = array_keys((array) config('buddy.knowledge.products', []));
        if (! in_array($product, $products, true)) {
            throw new \InvalidArgumentException('Unknown product. Expected one of: '.implode(', ', $products));
        }

        $environment = Str::slug((string) ($environment ?: config('buddy.knowledge.environment', 'staging')), '_');
        $records = [];

        foreach ($sourceArguments as $argument) {
            $source = $this->sources->fromArgument($argument, $revisionOverride);
            $records = [
                ...$records,
                ...$this->extractor->extract(
                    $source->path,
                    $product,
                    $source->repository,
                    $source->revision,
                    $environment,
                    $source->sourceUrl,
                ),
            ];
        }

        return new EcosystemKnowledgeIndex(
            name: "{$environment}_internal_{$product}_knowledge",
            records: $records,
        );
    }
}
