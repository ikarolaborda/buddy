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
 * The list is newest-first because negotiation walks it in order looking for
 * the newest entry that is not newer than what the client asked for.
 */
final class ProtocolVersions
{
    /*
     * Newest first. The stateless revision (2026-07-28) removes the
     * initialize handshake and protocol-level sessions; buddy never had
     * either, so the older entries stay for the deprecation window rather
     * than because anything here depends on them.
     */
    public const LATEST = '2026-07-28';

    /*
     * 2025-11-25 is not optional padding. Claude Code 2.1.227 asks for exactly
     * that revision, and omitting it took buddy off every Claude Code client:
     * negotiation fell through to LATEST, and the client disconnected with
     * "Server's protocol version is not supported: 2026-07-28". Leaving a gap
     * in the middle of this list is what makes negotiation fail, not being
     * behind on the newest entry.
     */
    public const SUPPORTED = [self::LATEST, '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /*
     * Modern per-request metadata begins at 2026-07-28. Older versions stay
     * available through the legacy handshake path, but must not be accepted
     * as a modern request just because they happen to appear in a header.
     */
    public const MODERN_SUPPORTED = [self::LATEST];

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

        $served = self::nearestNotNewerThan($requested);

        Log::warning('MCP protocol downgrade', [
            'requested' => $requested,
            'served' => $served,
            'client' => $clientLabel,
            'supported' => self::SUPPORTED,
        ]);

        return $served;
    }

    /*
     * Answering an unknown version with LATEST is spec-legal but maximally
     * brittle: a client asking for a revision we skipped gets handed something
     * NEWER than it can parse and disconnects, which is precisely how the
     * 2025-11-25 gap became an outage. Offering the newest version that is not
     * newer than the request keeps the suggestion inside what the client can
     * plausibly speak, since a client understands its own revision and the ones
     * before it.
     *
     * For a request newer than everything we know, or one that is not a version
     * at all, this still yields LATEST - so the only behaviour that changes is
     * the gap case that broke.
     */
    private static function nearestNotNewerThan(?string $requested): string
    {
        if ($requested === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) !== 1) {
            return self::LATEST;
        }

        foreach (self::SUPPORTED as $candidate) {
            if (strcmp($candidate, $requested) <= 0) {
                return $candidate;
            }
        }

        return self::LATEST;
    }

    public static function supports(?string $version): bool
    {
        return $version !== null && in_array($version, self::SUPPORTED, true);
    }

    public static function supportsModern(?string $version): bool
    {
        return $version !== null && in_array($version, self::MODERN_SUPPORTED, true);
    }
}
