<?php

namespace App\Mcp;

use Illuminate\Http\Request;

/*
 * Per-request client context, the way the stateless revision wants it.
 *
 * Before 2026-07-28 a client announced its protocol version, name and
 * capabilities once during initialize and the server was expected to
 * remember. Buddy never remembered - it is stateless and scales to five
 * replicas with no affinity, so there was nowhere to remember - which is
 * why it survives the change unharmed. What the new revision adds is a
 * place to put that information on EVERY request: `_meta`.
 *
 * Precedence is fixed here rather than left to each call site, because an
 * undefined order between "_meta says 2026-07-28" and "initialize once said
 * 2025-06-18" is the kind of ambiguity that produces bugs which reproduce
 * on one machine and not another:
 *
 *   1. `_meta` on this request wins.
 *   2. Otherwise whatever initialize negotiated, treated as advisory only.
 *   3. Otherwise our newest supported version.
 *
 * Never the reverse: a stale handshake must not override what the client
 * is telling us right now.
 */
final class RequestContext
{
    private const MODERN_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';

    private const MODERN_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';

    private const MODERN_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    private function __construct(
        public readonly ?string $protocolVersion,
        public readonly ?string $modernProtocolVersion,
        public readonly ?string $clientName,
        public readonly ?string $clientVersion,
        public readonly ?string $mcpProtocolVersionHeader,
        public readonly ?string $mcpMethodHeader,
        public readonly ?string $mcpNameHeader,
        public readonly bool $hasMeta,
        public readonly bool $hasModernMeta,
        public readonly bool $hasClientCapabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public static function fromMessage(array $message, ?Request $request = null): self
    {
        $params = $message['params'] ?? [];
        $params = is_array($params) ? $params : [];

        $meta = $params['_meta'] ?? null;
        $meta = is_array($meta) ? $meta : null;

        $modernProtocolVersion = self::stringOrNull($meta[self::MODERN_PROTOCOL_VERSION] ?? null);
        $legacyProtocolVersion = self::stringOrNull($meta['protocolVersion'] ?? null)
            ?? self::stringOrNull($params['protocolVersion'] ?? null);

        $clientInfo = $meta[self::MODERN_CLIENT_INFO] ?? ($meta['clientInfo'] ?? ($params['clientInfo'] ?? null));
        $clientInfo = is_array($clientInfo) ? $clientInfo : [];

        return new self(
            protocolVersion: $modernProtocolVersion ?? $legacyProtocolVersion,
            modernProtocolVersion: $modernProtocolVersion,
            clientName: self::stringOrNull($clientInfo['name'] ?? null),
            clientVersion: self::stringOrNull($clientInfo['version'] ?? null),
            mcpProtocolVersionHeader: self::stringOrNull($request?->header('MCP-Protocol-Version')),
            mcpMethodHeader: $request?->header('Mcp-Method'),
            mcpNameHeader: $request?->header('Mcp-Name'),
            hasMeta: $meta !== null,
            hasModernMeta: $meta !== null && (
                array_key_exists(self::MODERN_PROTOCOL_VERSION, $meta)
                || array_key_exists(self::MODERN_CLIENT_INFO, $meta)
                || array_key_exists(self::MODERN_CLIENT_CAPABILITIES, $meta)
            ),
            hasClientCapabilities: $meta !== null && array_key_exists(self::MODERN_CLIENT_CAPABILITIES, $meta),
        );
    }

    /*
     * Requests without a modern header or namespaced _meta retain the legacy
     * path for the deprecation window. A request that attempts the modern
     * shape is always validated strictly; it never silently falls back.
     */
    public function isModern(): bool
    {
        return $this->hasModernMeta || $this->mcpProtocolVersionHeader === ProtocolVersions::LATEST;
    }

    /**
     * Validate the request metadata that 2026-07-28 mirrors into headers.
     *
     * @param  array<string, mixed>  $message
     */
    public function validateModernRequest(array $message): void
    {
        if (! $this->hasModernMeta || $this->modernProtocolVersion === null || ! $this->hasClientCapabilities) {
            throw McpProtocolException::invalidParams(
                'Every request must include _meta.io.modelcontextprotocol/protocolVersion and _meta.io.modelcontextprotocol/clientCapabilities.',
            );
        }

        if ($this->mcpProtocolVersionHeader === null || ! self::isSafePlainHeaderValue($this->mcpProtocolVersionHeader)) {
            throw McpProtocolException::headerMismatch('MCP-Protocol-Version is required and must be a safe header value.');
        }

        if ($this->mcpProtocolVersionHeader !== $this->modernProtocolVersion) {
            throw McpProtocolException::headerMismatch('MCP-Protocol-Version does not match _meta.io.modelcontextprotocol/protocolVersion.');
        }

        if (! ProtocolVersions::supportsModern($this->mcpProtocolVersionHeader)) {
            throw McpProtocolException::unsupportedProtocolVersion($this->mcpProtocolVersionHeader);
        }

        $method = $message['method'] ?? null;

        if (! is_string($method) || $method === '') {
            throw McpProtocolException::invalidRequest('method must be a non-empty string.');
        }

        if ($this->mcpMethodHeader === null || ! self::isSafePlainHeaderValue($this->mcpMethodHeader)) {
            throw McpProtocolException::headerMismatch('Mcp-Method is required and must be a safe header value.');
        }

        if ($this->mcpMethodHeader !== $method) {
            throw McpProtocolException::headerMismatch('Mcp-Method does not match the JSON-RPC method.');
        }

        if (! in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true)) {
            return;
        }

        $params = $message['params'] ?? [];
        $params = is_array($params) ? $params : [];
        $sourceField = $method === 'resources/read' ? 'uri' : 'name';
        $sourceValue = $params[$sourceField] ?? null;

        if (! is_string($sourceValue) || $sourceValue === '') {
            throw McpProtocolException::headerMismatch("Mcp-Name requires a non-empty params.{$sourceField} value.");
        }

        $decodedName = self::decodeHeaderValue($this->mcpNameHeader);

        if ($decodedName === null) {
            throw McpProtocolException::headerMismatch('Mcp-Name is required and must be a valid plain or Base64-sentinel header value.');
        }

        if ($decodedName !== $sourceValue) {
            throw McpProtocolException::headerMismatch('Mcp-Name does not match the request body.');
        }
    }

    /*
     * A short label for logs. Never includes credentials.
     */
    public function label(): ?string
    {
        if ($this->clientName === null) {
            return null;
        }

        return $this->clientVersion === null
            ? $this->clientName
            : $this->clientName.'/'.$this->clientVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(string $method): array
    {
        return [
            'method' => $method,
            'has_meta' => $this->hasMeta,
            'has_modern_meta' => $this->hasModernMeta,
            'has_client_capabilities' => $this->hasClientCapabilities,
            'protocol_version' => $this->protocolVersion,
            'mcp_protocol_version_header' => $this->mcpProtocolVersionHeader,
            'client' => $this->label(),
            'mcp_method_header' => $this->mcpMethodHeader,
            'mcp_name_header' => $this->mcpNameHeader,
            'header_matches_method' => $this->mcpMethodHeader === null
                ? null
                : $this->mcpMethodHeader === $method,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function isSafePlainHeaderValue(string $value): bool
    {
        return preg_match('/^[\x21-\x7e]+$/D', $value) === 1;
    }

    private static function decodeHeaderValue(?string $value): ?string
    {
        if ($value === null || ! self::isSafePlainHeaderValue($value)) {
            return null;
        }

        $prefix = '=?base64?';
        $suffix = '?=';

        if (! str_starts_with($value, $prefix) || ! str_ends_with($value, $suffix)) {
            return $value;
        }

        $encoded = substr($value, strlen($prefix), -strlen($suffix));
        $decoded = $encoded === '' ? false : base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }
}
