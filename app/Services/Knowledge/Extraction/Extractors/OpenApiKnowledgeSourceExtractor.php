<?php

namespace App\Services\Knowledge\Extraction\Extractors;

use App\Services\Knowledge\Extraction\Contracts\KnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\ExtractedKnowledgeChunk;
use App\Services\Knowledge\Extraction\KnowledgeSourceFile;
use App\Services\Knowledge\Extraction\RepositoryPathPolicy;
use Illuminate\Support\Str;

final readonly class OpenApiKnowledgeSourceExtractor implements KnowledgeSourceExtractor
{
    private const HTTP_METHODS = ['delete', 'get', 'head', 'options', 'patch', 'post', 'put'];

    public function __construct(
        private RepositoryPathPolicy $pathPolicy,
    ) {}

    public function supports(KnowledgeSourceFile $source): bool
    {
        return $this->pathPolicy->isOpenApi($source);
    }

    public function extract(KnowledgeSourceFile $source): array
    {
        if ($source->extension() === 'json') {
            $document = json_decode($source->contents, true);
            if (is_array($document)) {
                return $this->jsonOperations($document);
            }
        }

        return $this->yamlOperations($source);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, ExtractedKnowledgeChunk>
     */
    private function jsonOperations(array $document): array
    {
        $chunks = [];

        foreach (($document['paths'] ?? []) as $route => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! in_array(strtolower((string) $method), self::HTTP_METHODS, true) || ! is_array($operation)) {
                    continue;
                }

                $name = strtoupper((string) $method).' '.$route;
                $chunks[] = new ExtractedKnowledgeChunk(
                    anchor: strtolower((string) $method).'-'.Str::slug((string) $route),
                    title: $name,
                    body: (string) json_encode([
                        'operationId' => $operation['operationId'] ?? null,
                        'summary' => $operation['summary'] ?? null,
                        'description' => $operation['description'] ?? null,
                        'parameters' => $operation['parameters'] ?? [],
                        'requestBody' => $operation['requestBody'] ?? null,
                        'responses' => array_keys((array) ($operation['responses'] ?? [])),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    contentType: 'api_contract',
                    symbolKind: 'operation',
                    symbolName: $operation['operationId'] ?? $name,
                    tags: ['openapi', strtolower((string) $method)],
                );
            }
        }

        return $chunks;
    }

    /**
     * @return array<int, ExtractedKnowledgeChunk>
     */
    private function yamlOperations(KnowledgeSourceFile $source): array
    {
        $chunks = [];
        $lines = $source->lines();

        foreach ($lines as $offset => $line) {
            if (preg_match('/^\s{2,}((?:get|post|put|patch|delete|head|options)):\s*$/i', $line, $method) !== 1) {
                continue;
            }

            $route = $this->nearestRoute($lines, $offset);
            $name = strtoupper($method[1]).' '.$route;
            $chunks[] = new ExtractedKnowledgeChunk(
                anchor: strtolower($method[1]).'-'.Str::slug($route),
                title: $name,
                body: implode("\n", array_slice($lines, $offset, 30)),
                contentType: 'api_contract',
                symbolKind: 'operation',
                symbolName: $name,
                line: $offset + 1,
                tags: ['openapi', strtolower($method[1])],
            );
        }

        return $chunks;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function nearestRoute(array $lines, int $offset): string
    {
        for ($cursor = $offset - 1; $cursor >= 0; $cursor--) {
            if (preg_match('/^\s{0,4}(\/[^:]+):\s*$/', $lines[$cursor], $match) === 1) {
                return $match[1];
            }
        }

        return 'unknown-route';
    }
}
