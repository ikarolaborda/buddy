<?php

namespace App\Services\Knowledge\Extraction;

use App\Services\Knowledge\Extraction\Contracts\KnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\Extractors\ConfigurationKnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\Extractors\MarkdownKnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\Extractors\OpenApiKnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\Extractors\SymbolKnowledgeSourceExtractor;

final readonly class CompositeKnowledgeSourceExtractor implements KnowledgeSourceExtractor
{
    /**
     * @var array<int, KnowledgeSourceExtractor>
     */
    private array $extractors;

    public function __construct(
        MarkdownKnowledgeSourceExtractor $markdown,
        OpenApiKnowledgeSourceExtractor $openApi,
        ConfigurationKnowledgeSourceExtractor $configuration,
        SymbolKnowledgeSourceExtractor $symbols,
    ) {
        $this->extractors = [$markdown, $openApi, $configuration, $symbols];
    }

    public function supports(KnowledgeSourceFile $source): bool
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($source)) {
                return true;
            }
        }

        return false;
    }

    public function extract(KnowledgeSourceFile $source): array
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($source)) {
                return $extractor->extract($source);
            }
        }

        return [];
    }
}
