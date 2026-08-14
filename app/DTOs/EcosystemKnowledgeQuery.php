<?php

namespace App\DTOs;

readonly class EcosystemKnowledgeQuery
{
    /**
     * @param  array<int, string>  $indices
     */
    public function __construct(
        public string $query,
        public array $indices,
        public int $limit,
        public ?string $product = null,
        public ?string $repository = null,
    ) {}

    public function hash(): string
    {
        return hash('sha256', json_encode([
            $this->query,
            $this->indices,
            $this->limit,
            $this->product,
            $this->repository,
        ], JSON_THROW_ON_ERROR));
    }
}
