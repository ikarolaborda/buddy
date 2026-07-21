<?php

namespace App\DTOs;

readonly class MemoryQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $query,
        public int $limit = 5,
        public array $filters = [],
        public ?string $project = null,
    ) {}
}
