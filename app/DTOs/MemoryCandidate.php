<?php

namespace App\DTOs;

readonly class MemoryCandidate
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $fileReferences
     */
    public function __construct(
        public string $summary,
        public array $tags = [],
        public array $payload = [],
        public ?string $project = null,
        public ?string $problem = null,
        public ?string $solution = null,
        public ?string $impact = null,
        public array $fileReferences = [],
    ) {}
}
