<?php

namespace App\Services\Knowledge\Indexing;

final readonly class RepositoryIndexSource
{
    public function __construct(
        public string $repository,
        public string $path,
        public string $revision,
        public ?string $sourceUrl,
    ) {}
}
