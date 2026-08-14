<?php

namespace App\Services\Knowledge;

use App\Services\Knowledge\Extraction\CompositeKnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\KnowledgeContentPolicy;
use App\Services\Knowledge\Extraction\KnowledgeExtractionContext;
use App\Services\Knowledge\Extraction\KnowledgeRecordFactory;
use App\Services\Knowledge\Extraction\RepositorySourceScanner;

final readonly class RepositoryKnowledgeExtractor
{
    public function __construct(
        private RepositorySourceScanner $scanner,
        private CompositeKnowledgeSourceExtractor $extractor,
        private KnowledgeContentPolicy $contentPolicy,
        private KnowledgeRecordFactory $recordFactory,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(
        string $root,
        string $product,
        string $repository,
        string $revision,
        string $environment,
        ?string $sourceBaseUrl = null,
    ): array {
        $context = KnowledgeExtractionContext::fromRepository(
            $root,
            $product,
            $repository,
            $revision,
            $environment,
            $sourceBaseUrl,
        );
        $records = [];

        foreach ($this->scanner->scan($context->root) as $source) {
            $this->contentPolicy->assertSafe($source);

            foreach ($this->extractor->extract($source) as $chunk) {
                $record = $this->recordFactory->make($source, $chunk, $context);
                if ($record !== null) {
                    $records[] = $record->toArray();
                }
            }
        }

        usort($records, static fn (array $left, array $right): int => [
            $left['source_path'], $left['title'],
        ] <=> [
            $right['source_path'], $right['title'],
        ]);

        return $records;
    }
}
