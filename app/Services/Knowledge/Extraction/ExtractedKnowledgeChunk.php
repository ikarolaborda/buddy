<?php

namespace App\Services\Knowledge\Extraction;

final readonly class ExtractedKnowledgeChunk
{
    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public string $anchor,
        public string $title,
        public string $body,
        public string $contentType,
        public ?string $symbolKind = null,
        public ?string $symbolName = null,
        public ?int $line = null,
        public array $tags = [],
    ) {}
}
