<?php

namespace App\Services\Knowledge\Extraction\Extractors;

final readonly class SymbolPattern
{
    public function __construct(
        public string $expression,
        public int $nameGroup,
        public ?int $kindGroup = null,
        public string $defaultKind = 'symbol',
    ) {}
}
