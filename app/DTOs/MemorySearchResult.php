<?php

namespace App\DTOs;

readonly class MemorySearchResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public string $pointId,
        public float $score,
        public string $summary,
        public array $payload = [],
        public array $tags = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromQdrantResult(array $data): self
    {
        $payload = $data['payload'] ?? [];

        return new self(
            pointId: (string) $data['id'],
            score: (float) $data['score'],
            summary: $payload['summary'] ?? '',
            payload: $payload,
            tags: $payload['tags'] ?? [],
        );
    }
}
