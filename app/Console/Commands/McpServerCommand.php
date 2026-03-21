<?php

namespace App\Console\Commands;

use App\Mcp\McpToolRegistry;
use App\Mcp\Tools\AttachArtifactMcpTool;
use App\Mcp\Tools\CloseTaskMcpTool;
use App\Mcp\Tools\GetRecommendationTool;
use App\Mcp\Tools\GetTaskStatusTool;
use App\Mcp\Tools\SearchMemoryMcpTool;
use App\Mcp\Tools\StoreMemoryMcpTool;
use App\Mcp\Tools\SubmitProblemTool;
use Illuminate\Console\Command;

class McpServerCommand extends Command
{
    protected $signature = 'buddy:mcp-server';

    protected $description = 'Start the Buddy MCP server (stdio transport)';

    protected McpToolRegistry $registry;

    public function handle(): int
    {
        $this->registry = $this->buildRegistry();

        // Disable output buffering for stdio
        if (ob_get_level()) {
            ob_end_flush();
        }

        $stdin = fopen('php://stdin', 'r');

        if (! $stdin) {
            $this->error('Failed to open stdin');

            return self::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);

            if (! is_array($request)) {
                $this->writeResponse($this->errorResponse(null, -32700, 'Parse error'));

                continue;
            }

            $response = $this->handleRequest($request);
            $this->writeResponse($response);
        }

        fclose($stdin);

        return self::SUCCESS;
    }

    protected function buildRegistry(): McpToolRegistry
    {
        $registry = new McpToolRegistry;
        $registry->register(new SubmitProblemTool);
        $registry->register(new GetTaskStatusTool);
        $registry->register(new GetRecommendationTool);
        $registry->register(new SearchMemoryMcpTool);
        $registry->register(new StoreMemoryMcpTool);
        $registry->register(new AttachArtifactMcpTool);
        $registry->register(new CloseTaskMcpTool);

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function handleRequest(array $request): array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'notifications/initialized' => [], // notification, no response needed
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $request['params'] ?? []),
            default => $this->errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleInitialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => new \stdClass,
                ],
                'serverInfo' => [
                    'name' => 'buddy',
                    'version' => '1.0.0',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleToolsList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => $this->registry->listTools(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function handleToolsCall(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        try {
            $result = $this->registry->call($toolName, $arguments);

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => "Error: {$e->getMessage()}"],
                    ],
                    'isError' => true,
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function writeResponse(array $response): void
    {
        if ($response === []) {
            return;
        }

        $json = json_encode($response, JSON_THROW_ON_ERROR);
        fwrite(STDOUT, $json."\n");
        fflush(STDOUT);
    }
}
