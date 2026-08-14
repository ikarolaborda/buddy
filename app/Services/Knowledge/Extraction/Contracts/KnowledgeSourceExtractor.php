<?php

namespace App\Services\Knowledge\Extraction\Contracts;

use App\Services\Knowledge\Extraction\ExtractedKnowledgeChunk;
use App\Services\Knowledge\Extraction\KnowledgeSourceFile;

interface KnowledgeSourceExtractor
{
    public function supports(KnowledgeSourceFile $source): bool;

    /**
     * @return array<int, ExtractedKnowledgeChunk>
     */
    public function extract(KnowledgeSourceFile $source): array;
}
