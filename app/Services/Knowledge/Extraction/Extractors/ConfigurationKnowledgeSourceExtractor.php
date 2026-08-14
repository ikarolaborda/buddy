<?php

namespace App\Services\Knowledge\Extraction\Extractors;

use App\Services\Knowledge\Extraction\Contracts\KnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\ExtractedKnowledgeChunk;
use App\Services\Knowledge\Extraction\KnowledgeSourceFile;
use App\Services\Knowledge\Extraction\RepositoryPathPolicy;

final readonly class ConfigurationKnowledgeSourceExtractor implements KnowledgeSourceExtractor
{
    public function __construct(
        private RepositoryPathPolicy $pathPolicy,
    ) {}

    public function supports(KnowledgeSourceFile $source): bool
    {
        return $this->pathPolicy->isConfiguration($source);
    }

    public function extract(KnowledgeSourceFile $source): array
    {
        $keys = $source->extension() === 'json'
            ? $this->jsonKeys($source)
            : $this->textKeys($source);

        if ($keys === []) {
            return [];
        }

        return [new ExtractedKnowledgeChunk(
            anchor: 'configuration-keys',
            title: basename($source->path).' configuration keys',
            body: implode("\n", $keys),
            contentType: 'config_contract',
            symbolKind: 'configuration',
            symbolName: basename($source->path),
            tags: ['configuration'],
        )];
    }

    /**
     * @return array<int, string>
     */
    private function jsonKeys(KnowledgeSourceFile $source): array
    {
        $decoded = json_decode($source->contents, true);
        if (! is_array($decoded)) {
            return [];
        }

        $keys = [];
        $this->collectJsonKeys($decoded, '', $keys);

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function textKeys(KnowledgeSourceFile $source): array
    {
        preg_match_all('/^[\t ]*[\'\"]?([A-Za-z][A-Za-z0-9_.-]*)[\'\"]?\s*(?:=>|:|=)/m', $source->contents, $matches);
        preg_match_all('/\benv\([\'\"]([A-Z][A-Z0-9_]*)[\'\"]/', $source->contents, $environmentMatches);

        return array_values(array_unique(array_filter([
            ...($matches[1] ?? []),
            ...($environmentMatches[1] ?? []),
        ])));
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @param  array<int, string>  $keys
     */
    private function collectJsonKeys(array $value, string $prefix, array &$keys): void
    {
        foreach ($value as $key => $child) {
            if (is_int($key)) {
                continue;
            }

            $path = ltrim($prefix.'.'.$key, '.');
            $keys[] = $path;
            if (is_array($child)) {
                $this->collectJsonKeys($child, $path, $keys);
            }
        }
    }
}
