<?php

namespace App\DTOs;

readonly class MemoryReceipt
{
    public function __construct(
        public string $memoryId,
        public string $backend,
    ) {}
}
