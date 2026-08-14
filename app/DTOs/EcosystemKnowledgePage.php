<?php

namespace App\DTOs;

readonly class EcosystemKnowledgePage
{
    /**
     * @param  array<int, EcosystemKnowledgeHit>  $results
     */
    public function __construct(
        public array $results,
        public bool $degraded = false,
        public ?string $degradedReason = null,
    ) {}

    public static function degraded(string $reason): self
    {
        return new self([], true, $reason);
    }
}
