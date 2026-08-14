<?php

namespace App\Services\Knowledge\Indexing;

use Illuminate\Support\Str;

final readonly class RepositoryIndexSourceFactory
{
    public function __construct(
        private GitRepositoryInspector $git,
    ) {}

    public function fromArgument(string $argument, ?string $revisionOverride = null): RepositoryIndexSource
    {
        [$repository, $unresolvedPath] = $this->splitArgument($argument);
        $path = realpath($unresolvedPath);

        if (! is_string($path) || ! is_dir($path)) {
            throw new \InvalidArgumentException("Repository source does not exist: {$unresolvedPath}");
        }

        $revision = $revisionOverride ?: $this->git->revision($path);
        if ($revision === '') {
            throw new \InvalidArgumentException("Cannot determine a revision for {$repository}; pass --revision.");
        }

        return new RepositoryIndexSource(
            repository: Str::slug($repository),
            path: $path,
            revision: $revision,
            sourceUrl: $this->git->sourceUrl($path),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitArgument(string $argument): array
    {
        if (str_contains($argument, '=')) {
            return explode('=', $argument, 2);
        }

        return [basename(rtrim($argument, DIRECTORY_SEPARATOR)), $argument];
    }
}
