<?php

namespace App\Ai\Prompting;

readonly class PromptBundle
{
    /**
     * @param  array<int, string>  $moduleIds
     * @param  array<string, string>  $moduleHashes
     */
    public function __construct(
        public string $agent,
        public array $moduleIds,
        public array $moduleHashes,
        public string $text,
        public string $contentHash,
    ) {}
}
