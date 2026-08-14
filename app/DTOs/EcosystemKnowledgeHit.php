<?php

namespace App\DTOs;

readonly class EcosystemKnowledgeHit
{
    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public string $recordId,
        public string $index,
        public float $score,
        public string $title,
        public string $snippet,
        public string $product,
        public string $repository,
        public string $contentType,
        public string $sourcePath,
        public string $sourceRevision,
        public ?string $sourceUrl = null,
        public array $tags = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'record_id' => $this->recordId,
            'index' => $this->index,
            'score' => round($this->score, 6),
            'title' => $this->title,
            'snippet' => $this->snippet,
            'product' => $this->product,
            'repository' => $this->repository,
            'content_type' => $this->contentType,
            'source_path' => $this->sourcePath,
            'source_revision' => $this->sourceRevision,
            'source_url' => $this->sourceUrl,
            'tags' => $this->tags,
            'snippet_hash' => hash('sha256', $this->snippet),
        ];
    }
}
