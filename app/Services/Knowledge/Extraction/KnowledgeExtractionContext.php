<?php

namespace App\Services\Knowledge\Extraction;

final readonly class KnowledgeExtractionContext
{
    public function __construct(
        public string $root,
        public string $product,
        public string $repository,
        public string $revision,
        public string $environment,
        public ?string $sourceBaseUrl,
    ) {}

    public static function fromRepository(
        string $root,
        string $product,
        string $repository,
        string $revision,
        string $environment,
        ?string $sourceBaseUrl,
    ): self {
        $resolvedRoot = realpath($root);
        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot)) {
            throw new \InvalidArgumentException("Repository path does not exist: {$root}");
        }

        return new self(
            root: rtrim($resolvedRoot, DIRECTORY_SEPARATOR),
            product: $product,
            repository: $repository,
            revision: $revision,
            environment: $environment,
            sourceBaseUrl: $sourceBaseUrl,
        );
    }

    public function sourceUrl(KnowledgeSourceFile $source, ?int $line): ?string
    {
        if ($this->sourceBaseUrl === null || $this->sourceBaseUrl === '') {
            return null;
        }

        $base = rtrim(preg_replace('/\.git$/', '', $this->sourceBaseUrl) ?? $this->sourceBaseUrl, '/');
        $url = "{$base}/blob/{$this->revision}/{$source->path}";

        return $line === null ? $url : "{$url}#L{$line}";
    }
}
