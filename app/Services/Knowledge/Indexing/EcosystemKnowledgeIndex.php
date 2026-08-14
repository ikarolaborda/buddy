<?php

namespace App\Services\Knowledge\Indexing;

final readonly class EcosystemKnowledgeIndex
{
    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function __construct(
        public string $name,
        public array $records,
    ) {}

    public function count(): int
    {
        return count($this->records);
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }
}
