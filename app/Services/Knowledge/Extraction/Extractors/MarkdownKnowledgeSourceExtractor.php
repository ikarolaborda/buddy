<?php

namespace App\Services\Knowledge\Extraction\Extractors;

use App\Services\Knowledge\Extraction\Contracts\KnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\ExtractedKnowledgeChunk;
use App\Services\Knowledge\Extraction\KnowledgeSourceFile;
use App\Services\Knowledge\Extraction\RepositoryPathPolicy;
use Illuminate\Support\Str;

final readonly class MarkdownKnowledgeSourceExtractor implements KnowledgeSourceExtractor
{
    private const MAX_BODY_CHARS = 4000;

    public function __construct(
        private RepositoryPathPolicy $pathPolicy,
    ) {}

    public function supports(KnowledgeSourceFile $source): bool
    {
        return $this->pathPolicy->isMarkdown($source);
    }

    public function extract(KnowledgeSourceFile $source): array
    {
        $chunks = [];
        $section = new MarkdownSection(basename($source->path), 1);

        foreach ($source->lines() as $lineNumber => $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $match) === 1) {
                $chunks = [...$chunks, ...$this->chunksFor($source, $section)];
                $section = new MarkdownSection(trim($match[1]), $lineNumber + 1);
            }

            $section->append($line);
        }

        return [...$chunks, ...$this->chunksFor($source, $section)];
    }

    /**
     * @return array<int, ExtractedKnowledgeChunk>
     */
    private function chunksFor(KnowledgeSourceFile $source, MarkdownSection $section): array
    {
        if ($section->isEmpty()) {
            return [];
        }

        $chunks = [];
        foreach ($section->chunks(self::MAX_BODY_CHARS) as $offset => $body) {
            $chunks[] = new ExtractedKnowledgeChunk(
                anchor: Str::slug($section->title).'-'.$section->line.'-'.$offset,
                title: $section->title,
                body: $body,
                contentType: str_contains(strtolower($source->path), '/adr') ? 'adr' : 'documentation',
                line: $section->line,
                tags: str_contains(strtolower($source->path), 'agents.md') ? ['agent-instructions'] : [],
            );
        }

        return $chunks;
    }
}
