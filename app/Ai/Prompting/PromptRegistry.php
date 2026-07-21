<?php

namespace App\Ai\Prompting;

use RuntimeException;

class PromptRegistry
{
    /**
     * @var array<string, string>
     */
    protected array $cache = [];

    public function module(string $moduleId): string
    {
        if (isset($this->cache[$moduleId])) {
            return $this->cache[$moduleId];
        }

        $path = $this->basePath().'/'.$moduleId.'.md';

        if (! is_file($path)) {
            throw new RuntimeException("Prompt module not found: {$moduleId}");
        }

        return $this->cache[$moduleId] = trim((string) file_get_contents($path));
    }

    public function hash(string $moduleId): string
    {
        return hash('sha256', $this->module($moduleId));
    }

    protected function basePath(): string
    {
        return resource_path('prompts');
    }
}
