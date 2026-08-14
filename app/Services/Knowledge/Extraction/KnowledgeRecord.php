<?php

namespace App\Services\Knowledge\Extraction;

final readonly class KnowledgeRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private array $attributes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
