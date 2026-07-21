<?php

namespace App\DTOs;

readonly class MemorySearchPage
{
    /**
     * @param  array<int, MemorySearchResult>  $results
     */
    public function __construct(
        public array $results,
        public string $backend,
        public bool $degraded = false,
        public ?string $degradedReason = null,
    ) {}

    public static function degraded(string $backend, string $reason): self
    {
        return new self(
            results: [],
            backend: $backend,
            degraded: true,
            degradedReason: $reason,
        );
    }
}
