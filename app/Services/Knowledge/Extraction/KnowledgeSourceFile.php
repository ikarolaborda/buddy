<?php

namespace App\Services\Knowledge\Extraction;

final readonly class KnowledgeSourceFile
{
    public function __construct(
        public string $path,
        public string $contents,
    ) {}

    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    public function basename(): string
    {
        return strtolower(basename($this->path));
    }

    /**
     * @return array<int, string>
     */
    public function lines(): array
    {
        return preg_split('/\R/', $this->contents) ?: [];
    }

    public function lineNumberAt(int $byteOffset): int
    {
        return substr_count(substr($this->contents, 0, $byteOffset), "\n") + 1;
    }

    public function isBinary(): bool
    {
        return str_contains($this->contents, "\0");
    }
}
