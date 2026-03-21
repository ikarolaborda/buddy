<?php

namespace App\Mcp;

abstract class BaseMcpTool implements McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
        ];
    }
}
