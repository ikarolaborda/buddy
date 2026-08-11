<?php

namespace App\Mcp;

use Illuminate\Support\Facades\Log;

/*
 * The one place the MCP protocol version list lives.
 *
 * It used to be a byte-identical array in three files: RemoteMcpHandler (the
 * HTTP surface production serves), McpServerCommand (stdio) and the
 * bin/buddy-mcp-bridge shim. Three copies of a constant that must agree is
 * three chances to disagree, and the surfaces had already drifted apart in
 * tool count. A version bump that lands in one of them ships a server that
 * announces different protocols depending on how you reach it.
 *
 * 2026-07-28 is first because negotiation prefers the newest supported
 * version when the client asks for something we do not know.
 */
final class ProtocolVersions
{
    /*
     * Newest first. The stateless revision (2026-07-28) removes the
     * initialize handshake and protocol-level sessions; buddy never had
     * either, so the older entries stay for the deprecation window rather
     * than because anything here depends on them.
     */
    public const SUPPORTED = ['2026-07-28', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const LATEST = self::SUPPORTED[0];

    /*
     * The version the client announced, when we speak it; otherwise our
     * newest. Answering with a different version than the one requested is
     * spec-legal - the client decides whether it can proceed - but doing it
     * SILENTLY is how a migrated client ends up speaking an old protocol
     * while every response looks like success. The downgrade is therefore
     * logged with the client identity, which is the whole point of this
     * method existing instead of an inline in_array().
     */
    public static function negotiate(?string $requested, ?string $clientLabel = null): string
    {
        if ($requested !== null && in_array($requested, self::SUPPORTED, true)) {
            return $requested;
        }

        Log::warning('MCP protocol downgrade', [
            'requested' => $requested,
            'served' => self::LATEST,
            'client' => $clientLabel,
            'supported' => self::SUPPORTED,
        ]);

        return self::LATEST;
    }

    public static function supports(?string $version): bool
    {
        return $version !== null && in_array($version, self::SUPPORTED, true);
    }
}
