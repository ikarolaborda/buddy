<?php

namespace App\Mcp;

interface McpTool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @return array<string, mixed>
     */
    public function describe(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array;
}
