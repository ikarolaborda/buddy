<?php

namespace App\Services\Knowledge\Extraction;

use Illuminate\Support\Str;

final readonly class KnowledgeRecordFactory
{
    private const MAX_BODY_CHARS = 4000;

    public function __construct(
        private KnowledgeContentPolicy $contentPolicy,
    ) {}

    public function make(
        KnowledgeSourceFile $source,
        ExtractedKnowledgeChunk $chunk,
        KnowledgeExtractionContext $context,
    ): ?KnowledgeRecord {
        $body = $this->contentPolicy->sanitize($chunk->body);
        if ($body === '') {
            return null;
        }

        $canonicalId = hash('sha256', implode('|', [
            $context->product,
            $context->repository,
            $source->path,
            $chunk->anchor,
        ]));
        $indexedAt = now();

        return new KnowledgeRecord([
            'objectID' => hash('sha256', $canonicalId.'|'.$context->revision),
            'canonical_id' => $canonicalId,
            'product' => $context->product,
            'repository' => $context->repository,
            'environment' => $context->environment,
            'content_type' => $chunk->contentType,
            'title' => $this->contentPolicy->sanitize($chunk->title),
            'body' => Str::limit($body, self::MAX_BODY_CHARS, ''),
            'snippet' => Str::limit(Str::squish($body), 1000, ''),
            'symbol_kind' => $chunk->symbolKind,
            'symbol_name' => $chunk->symbolName,
            'source_path' => $source->path,
            'source_revision' => $context->revision,
            'source_url' => $context->sourceUrl($source, $chunk->line),
            'tags' => array_values(array_unique([
                $context->product,
                $context->repository,
                $chunk->contentType,
                ...$chunk->tags,
            ])),
            'visibility' => 'internal',
            'status' => 'current',
            'indexed_at' => $indexedAt->toISOString(),
            'indexed_at_ts' => $indexedAt->timestamp,
        ]);
    }
}
