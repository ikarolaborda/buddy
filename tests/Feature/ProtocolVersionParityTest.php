<?php

namespace Tests\Feature;

use App\Mcp\ProtocolVersions;
use App\Mcp\RemoteToolDefinitions;
use App\Mcp\RequestContext;
use App\Mcp\UsageInstructions;
use Tests\TestCase;

/*
 * Guards the 2026-07-28 migration against the failure modes that made it
 * risky in the first place, all of which are silent by nature: a version
 * list that drifts between surfaces, a negotiation that downgrades without
 * telling anyone, and a close protocol that stops being taught the moment
 * clients stop calling initialize.
 */
class ProtocolVersionParityTest extends TestCase
{
    public function test_it_announces_the_stateless_protocol_version(): void
    {
        $this->assertSame('2026-07-28', ProtocolVersions::LATEST);
        $this->assertContains('2026-07-28', ProtocolVersions::SUPPORTED);
    }

    public function test_it_keeps_the_older_versions_for_the_deprecation_window(): void
    {
        foreach (['2025-06-18', '2025-03-26', '2024-11-05'] as $version) {
            $this->assertContains($version, ProtocolVersions::SUPPORTED);
        }
    }

    /*
     * The stdio bridge runs standalone without the framework autoloader, so
     * it cannot import the class and has to carry its own copy. That copy
     * is exactly the drift this whole test file exists to prevent, so it is
     * compared literally against the source of truth.
     */
    public function test_it_keeps_the_standalone_bridge_in_step(): void
    {
        $bridge = file_get_contents(base_path('bin/buddy-mcp-bridge'));

        $this->assertIsString($bridge);
        $this->assertSame(
            1,
            preg_match('/const SUPPORTED_PROTOCOL_VERSIONS = \[(.*?)\];/s', $bridge, $matches),
            'The bridge must declare SUPPORTED_PROTOCOL_VERSIONS.'
        );

        preg_match_all("/'([0-9]{4}-[0-9]{2}-[0-9]{2})'/", $matches[1], $found);

        $this->assertSame(
            ProtocolVersions::SUPPORTED,
            $found[1],
            'bin/buddy-mcp-bridge has drifted from App\Mcp\ProtocolVersions::SUPPORTED.'
        );
    }

    public function test_it_returns_the_requested_version_when_supported(): void
    {
        $this->assertSame('2026-07-28', ProtocolVersions::negotiate('2026-07-28'));
        $this->assertSame('2025-06-18', ProtocolVersions::negotiate('2025-06-18'));
    }

    /*
     * The old behaviour answered every unknown version with 2025-06-18 and
     * said nothing, so a client that had migrated to the stateless spec got
     * a success-shaped response and silently spoke the old protocol. The
     * fallback itself is spec-legal; the silence was the defect.
     */
    public function test_it_serves_the_newest_version_when_the_request_is_unknown(): void
    {
        $this->assertSame('2026-07-28', ProtocolVersions::negotiate('1999-01-01'));
        $this->assertSame('2026-07-28', ProtocolVersions::negotiate(null));
    }

    public function test_it_reads_protocol_and_client_from_meta(): void
    {
        $context = RequestContext::fromMessage([
            'method' => 'tools/call',
            'params' => [
                '_meta' => [
                    'protocolVersion' => '2026-07-28',
                    'clientInfo' => ['name' => 'claude-code', 'version' => '9.9.9'],
                ],
            ],
        ]);

        $this->assertTrue($context->hasMeta);
        $this->assertSame('2026-07-28', $context->protocolVersion);
        $this->assertSame('claude-code/9.9.9', $context->label());
    }

    /*
     * Precedence, fixed so it cannot become environment-dependent: what the
     * client says on THIS request outranks anything a previous handshake
     * negotiated.
     */
    public function test_it_prefers_meta_over_top_level_params(): void
    {
        $context = RequestContext::fromMessage([
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                '_meta' => ['protocolVersion' => '2026-07-28'],
            ],
        ]);

        $this->assertSame('2026-07-28', $context->protocolVersion);
    }

    public function test_it_still_reads_a_legacy_request_without_meta(): void
    {
        $context = RequestContext::fromMessage([
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18'],
        ]);

        $this->assertFalse($context->hasMeta);
        $this->assertSame('2025-06-18', $context->protocolVersion);
    }

    /*
     * The corpus-protection test. Under the stateless revision a client may
     * never call initialize, which was the only place the close protocol was
     * taught. If it is not on the tool itself, outcome labelling decays and
     * nothing fails loudly.
     */
    public function test_it_teaches_the_close_protocol_without_the_handshake(): void
    {
        $tools = collect(RemoteToolDefinitions::all());
        $close = $tools->firstWhere('name', 'buddy.close_task');

        $this->assertNotNull($close);
        $this->assertStringContainsString(UsageInstructions::CLOSE_PROTOCOL, $close['description']);
    }

    public function test_it_memoises_tool_definitions_without_changing_them(): void
    {
        RemoteToolDefinitions::flush();

        $first = RemoteToolDefinitions::all();
        $second = RemoteToolDefinitions::all();

        $this->assertSame($first, $second);
        $this->assertCount(7, $first);
    }
}
