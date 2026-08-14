<?php

namespace App\Services\Knowledge\Indexing;

final class KnowledgeIndexManifestWriter
{
    public function write(EcosystemKnowledgeIndex $index, string $outputPath): void
    {
        $directory = dirname($outputPath);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create output directory: {$directory}");
        }

        $encoded = json_encode($index->records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($outputPath, $encoded.PHP_EOL) === false) {
            throw new \RuntimeException("Unable to write index manifest: {$outputPath}");
        }
    }
}
