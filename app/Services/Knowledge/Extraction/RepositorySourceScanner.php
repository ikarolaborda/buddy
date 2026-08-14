<?php

namespace App\Services\Knowledge\Extraction;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class RepositorySourceScanner
{
    public function __construct(
        private RepositoryPathPolicy $pathPolicy,
    ) {}

    /**
     * @return iterable<int, KnowledgeSourceFile>
     */
    public function scan(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (! $this->pathPolicy->shouldIndex($file, $relativePath)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            $source = new KnowledgeSourceFile($relativePath, $contents);
            if (! $source->isBinary()) {
                yield $source;
            }
        }
    }
}
