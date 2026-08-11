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
    private function __construct(
        public readonly ?string $protocolVersion,
        public readonly ?string $clientName,
        public readonly ?string $clientVersion,
        public readonly ?string $mcpMethodHeader,
        public readonly ?string $mcpNameHeader,
        public readonly bool $hasMeta,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public static function fromMessage(array $message, ?Request $request = null): self
    {
        $meta = $message['params']['_meta'] ?? null;
        $meta = is_array($meta) ? $meta : null;

        $clientInfo = $meta['clientInfo'] ?? ($message['params']['clientInfo'] ?? null);
        $clientInfo = is_array($clientInfo) ? $clientInfo : [];

        return new self(
            protocolVersion: self::stringOrNull($meta['protocolVersion'] ?? null)
                ?? self::stringOrNull($message['params']['protocolVersion'] ?? null),
            clientName: self::stringOrNull($clientInfo['name'] ?? null),
            clientVersion: self::stringOrNull($clientInfo['version'] ?? null),
            mcpMethodHeader: $request?->header('Mcp-Method'),
            mcpNameHeader: $request?->header('Mcp-Name'),
            hasMeta: $meta !== null,
        );
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
            'protocol_version' => $this->protocolVersion,
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
}
