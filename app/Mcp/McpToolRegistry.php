<?php

namespace App\Mcp;

class McpToolRegistry
{
    /** @var array<string, McpTool> */
    protected array $tools = [];

    public function register(McpTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?McpTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTools(): array
    {
        return array_values(array_map(
            fn (McpTool $tool) => $tool->describe(),
            $this->tools,
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments = []): array
    {
        $tool = $this->get($name);

        if (! $tool) {
            throw new \InvalidArgumentException("Unknown tool: {$name}");
        }

        return $tool->handle($arguments);
    }
}
