<?php

namespace App\DTOs;

readonly class MemoryFeedback
{
    public function __construct(
        public string $memoryId,
        public bool $useful,
        public ?string $note = null,
    ) {}
}
