<?php

namespace Tests\Unit;

use App\Services\Diagnostics\CaptureTargetPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CaptureTargetPolicyTest extends TestCase
{
    private const POLICY = ['allowed_hosts' => ['example.com', 'Docs.Example.COM']];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedTargets(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com/', CaptureTargetPolicy::SCHEME_NOT_ALLOWED],
            'file scheme' => ['file:///etc/passwd', CaptureTargetPolicy::URL_INVALID],
            'javascript scheme' => ['javascript:alert(1)', CaptureTargetPolicy::URL_INVALID],
            'no scheme' => ['example.com/path', CaptureTargetPolicy::URL_INVALID],
            'userinfo' => ['https://user:secret@example.com/', CaptureTargetPolicy::USERINFO_NOT_ALLOWED],
            'user only' => ['https://user@example.com/', CaptureTargetPolicy::USERINFO_NOT_ALLOWED],
            'loopback v4' => ['http://127.0.0.1/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'loopback v6' => ['http://[::1]/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'rfc1918 10' => ['http://10.0.0.1/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'rfc1918 172' => ['http://172.31.255.254/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'rfc1918 192' => ['http://192.168.1.1/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'cgnat' => ['http://100.64.0.1/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'unspecified' => ['http://0.0.0.0/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'mapped v4 in v6' => ['http://[::ffff:10.0.0.1]/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'ula v6' => ['http://[fd00::1]/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'link-local v6' => ['http://[fe80::1]/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'public v4 literal' => ['http://93.184.216.34/', CaptureTargetPolicy::IP_LITERAL_NOT_ALLOWED],
            'public v6 literal' => ['https://[2606:4700::1111]/', CaptureTargetPolicy::IP_LITERAL_NOT_ALLOWED],
            'decimal literal' => ['http://2130706433/', CaptureTargetPolicy::IP_LITERAL_NOT_ALLOWED],
            'hex literal' => ['http://0x7f000001/', CaptureTargetPolicy::IP_LITERAL_NOT_ALLOWED],
            'octal literal' => ['http://0177.0.0.1/', CaptureTargetPolicy::IP_LITERAL_NOT_ALLOWED],
            'punycode' => ['https://xn--exmple-cua.com/', CaptureTargetPolicy::PUNYCODE_NOT_ALLOWED],
            'punycode subdomain' => ['https://xn--80ak6aa92e.example.com/', CaptureTargetPolicy::PUNYCODE_NOT_ALLOWED],
            'host not allowed' => ['https://other.example.com/', CaptureTargetPolicy::HOST_NOT_ALLOWED],
            'suffix match is not a match' => ['https://notexample.com/', CaptureTargetPolicy::HOST_NOT_ALLOWED],
            'localhost' => ['http://localhost/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'localhost subdomain' => ['http://app.localhost:3000/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'metadata host' => ['http://metadata.google.internal/computeMetadata/v1/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'internal suffix' => ['https://vault.internal/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'local suffix' => ['https://printer.local/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'home.arpa suffix' => ['https://nas.home.arpa/', CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED],
            'lookalike digit one' => ['https://examp1e.com/', CaptureTargetPolicy::LOOKALIKE_NOT_ALLOWED],
            'lookalike digit zero' => ['https://example.c0m/', CaptureTargetPolicy::LOOKALIKE_NOT_ALLOWED],
            'lookalike rn' => ['https://exarnple.com/', CaptureTargetPolicy::LOOKALIKE_NOT_ALLOWED],
            'lookalike unicode' => ['https://exаmple.com/', CaptureTargetPolicy::LOOKALIKE_NOT_ALLOWED],
            'lookalike percent-encoded' => ['https://ex%61mple.com/', CaptureTargetPolicy::LOOKALIKE_NOT_ALLOWED],
            'lookalike underscore' => ['https://exa_mple.com/', CaptureTargetPolicy::LOOKALIKE_NOT_ALLOWED],
        ];
    }

    #[DataProvider('rejectedTargets')]
    public function test_rejected_targets_carry_a_stable_code(string $url, string $code): void
    {
        $verdict = (new CaptureTargetPolicy)->evaluate($url, self::POLICY);

        $this->assertFalse($verdict['allowed']);
        $this->assertSame($code, $verdict['code']);
        $this->assertNull($verdict['normalized_url']);
    }

    public function test_urls_over_the_limit_are_refused_before_parsing(): void
    {
        $url = 'https://example.com/'.str_repeat('a', CaptureTargetPolicy::MAX_URL_LENGTH);

        $this->assertSame(CaptureTargetPolicy::URL_TOO_LONG, (new CaptureTargetPolicy)->evaluate($url, self::POLICY)['code']);
        $this->assertTrue((new CaptureTargetPolicy)->evaluate(substr($url, 0, CaptureTargetPolicy::MAX_URL_LENGTH), self::POLICY)['allowed']);
    }

    public function test_allowed_host_matches_exactly_and_case_insensitively(): void
    {
        $policy = new CaptureTargetPolicy;

        $verdict = $policy->evaluate('HTTPS://Example.COM./status?x=1#top', self::POLICY);
        $this->assertTrue($verdict['allowed']);
        $this->assertNull($verdict['code']);
        $this->assertSame('example.com', $verdict['host']);
        $this->assertSame('https://example.com/status?x=1', $verdict['normalized_url']);

        $verdict = $policy->evaluate('http://docs.example.com:8080', self::POLICY);
        $this->assertTrue($verdict['allowed']);
        $this->assertSame('http://docs.example.com:8080/', $verdict['normalized_url']);
    }

    public function test_wildcards_and_empty_allowlists_never_match(): void
    {
        $policy = new CaptureTargetPolicy;

        $this->assertSame(CaptureTargetPolicy::HOST_NOT_ALLOWED, $policy->evaluate('https://a.example.com/', ['allowed_hosts' => ['*.example.com']])['code']);
        $this->assertSame(CaptureTargetPolicy::HOST_NOT_ALLOWED, $policy->evaluate('https://example.com/', ['allowed_hosts' => []])['code']);
        $this->assertSame(CaptureTargetPolicy::HOST_NOT_ALLOWED, $policy->evaluate('https://example.com/', [])['code']);
    }

    public function test_private_and_literal_targets_are_refused_even_when_allowlisted(): void
    {
        $policy = new CaptureTargetPolicy;

        $this->assertSame(CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED, $policy->evaluate('http://localhost/', ['allowed_hosts' => ['localhost']])['code']);
        $this->assertSame(CaptureTargetPolicy::PRIVATE_HOST_NOT_ALLOWED, $policy->evaluate('http://10.0.0.1/', ['allowed_hosts' => ['10.0.0.1']])['code']);
        $this->assertSame(CaptureTargetPolicy::IP_LITERAL_NOT_ALLOWED, $policy->evaluate('http://2130706433/', ['allowed_hosts' => ['2130706433']])['code']);
        $this->assertSame(CaptureTargetPolicy::PUNYCODE_NOT_ALLOWED, $policy->evaluate('https://xn--exmple-cua.com/', ['allowed_hosts' => ['xn--exmple-cua.com']])['code']);
    }

    public function test_host_normalization_is_deterministic(): void
    {
        $this->assertSame(
            ['a.example.com', 'example.com'],
            (new CaptureTargetPolicy)->normalizeHosts(['Example.COM.', 'a.example.com', 'example.com', '', 42]),
        );
    }
}
