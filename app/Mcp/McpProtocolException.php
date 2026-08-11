<?php

namespace App\Mcp;

use RuntimeException;

/*
 * A protocol failure has both a JSON-RPC shape and an HTTP status in the
 * 2026-07-28 Streamable HTTP transport. Keeping them together prevents the
 * controller from accidentally returning a success HTTP status for a modern
 * protocol error.
 */
final class McpProtocolException extends RuntimeException
{
    private function __construct(
        public readonly int $httpStatus,
        public readonly int $jsonRpcCode,
        string $message,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message, $jsonRpcCode);
    }

    public static function headerMismatch(string $message): self
    {
        return new self(400, -32020, 'Header mismatch: '.$message);
    }

    public static function invalidParams(string $message): self
    {
        return new self(400, -32602, 'Invalid params: '.$message);
    }

    public static function invalidRequest(string $message): self
    {
        return new self(400, -32600, 'Invalid request: '.$message);
    }

    public static function unsupportedProtocolVersion(?string $requested): self
    {
        return new self(
            400,
            -32022,
            'Unsupported protocol version: '.($requested ?? '(missing)'),
            ['supportedVersions' => ProtocolVersions::MODERN_SUPPORTED],
        );
    }

    public static function methodNotFound(string $method): self
    {
        return new self(404, -32601, "Method not found: {$method}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(mixed $id): array
    {
        $error = [
            'code' => $this->jsonRpcCode,
            'message' => $this->getMessage(),
        ];

        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
