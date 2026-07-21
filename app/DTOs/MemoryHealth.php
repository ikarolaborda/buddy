<?php

namespace App\DTOs;

readonly class MemoryHealth
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $healthy,
        public string $backend,
        public array $details = [],
    ) {}
}
