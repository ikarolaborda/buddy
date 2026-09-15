<?php

namespace App\Services\Diagnostics;

/*
 * Request-time gate for a diagnostic capture target (plan §11, ADR 0013).
 * Every decision here is made from the URL text alone, so this class cannot
 * see what a hostname resolves to when the browser navigates: DNS rebinding,
 * and a public name that answers with a private address, are outside its
 * reach. The Worker applies the same policy again at navigation time against
 * the resolved connection and against every redirect and subresource, which
 * is why the rejection codes are stable strings shared by both sides.
 */
final class CaptureTargetPolicy
{
    public const MAX_URL_LENGTH = 2048;

    public const URL_TOO_LONG = 'url_too_long';

    public const URL_INVALID = 'url_invalid';

    public const SCHEME_NOT_ALLOWED = 'scheme_not_allowed';

    public const USERINFO_NOT_ALLOWED = 'userinfo_not_allowed';

    public const IP_LITERAL_NOT_ALLOWED = 'ip_literal_not_allowed';

    public const PUNYCODE_NOT_ALLOWED = 'punycode_not_allowed';

    public const HOST_NOT_ALLOWED = 'host_not_allowed';

    public const PRIVATE_HOST_NOT_ALLOWED = 'private_host_not_allowed';

    public const LOOKALIKE_NOT_ALLOWED = 'lookalike_not_allowed';

    private const SCHEMES = ['http', 'https'];

    private const PRIVATE_HOSTS = ['localhost', 'metadata.google.internal'];

    private const PRIVATE_SUFFIXES = ['.localhost', '.internal', '.local', '.home.arpa'];

    private const PRIVATE_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const PRIVATE_V6 = [
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * @param  array{allowed_hosts?: array<int, string>}  $policy
     * @return array{allowed: bool, code: ?string, host: ?string, normalized_url: ?string}
     */
    public function evaluate(string $url, array $policy): array
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            return $this->deny(self::URL_TOO_LONG);
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return $this->deny(self::URL_INVALID);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, self::SCHEMES, true)) {
            return $this->deny(self::SCHEME_NOT_ALLOWED);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $this->deny(self::USERINFO_NOT_ALLOWED);
        }

        $host = rtrim(strtolower($parts['host']), '.');
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $this->isPrivateAddress($literal)
                ? $this->deny(self::PRIVATE_HOST_NOT_ALLOWED, $host)
                : $this->deny(self::IP_LITERAL_NOT_ALLOWED, $host);
        }

        if ($this->looksNumeric($host)) {
            return $this->deny(self::IP_LITERAL_NOT_ALLOWED, $host);
        }

        // Percent-encoding, raw Unicode, underscores and brackets all land here:
        // a hostname the browser could reinterpret is never compared further.
        if (preg_match('/[^a-z0-9.-]/', $host) === 1) {
            return $this->deny(self::LOOKALIKE_NOT_ALLOWED, $host);
        }

        foreach (explode('.', $host) as $label) {
            if (str_starts_with($label, 'xn--')) {
                return $this->deny(self::PUNYCODE_NOT_ALLOWED, $host);
            }
        }

        if ($this->isPrivateName($host)) {
            return $this->deny(self::PRIVATE_HOST_NOT_ALLOWED, $host);
        }

        $allowed = $this->normalizeHosts($policy['allowed_hosts'] ?? []);

        if (! in_array($host, $allowed, true)) {
            $code = in_array($this->fold($host), array_map($this->fold(...), $allowed), true)
                ? self::LOOKALIKE_NOT_ALLOWED
                : self::HOST_NOT_ALLOWED;

            return $this->deny($code, $host);
        }

        return [
            'allowed' => true,
            'code' => null,
            'host' => $host,
            'normalized_url' => $this->normalize($parts, $scheme, $host),
        ];
    }

    /**
     * @param  array<int, mixed>  $hosts
     * @return array<int, string>
     */
    public function normalizeHosts(array $hosts): array
    {
        $normalized = [];

        foreach ($hosts as $host) {
            if (! is_string($host)) {
                continue;
            }

            $host = rtrim(strtolower(trim($host)), '.');

            if ($host !== '') {
                $normalized[] = $host;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return array{allowed: false, code: string, host: ?string, normalized_url: null}
     */
    private function deny(string $code, ?string $host = null): array
    {
        return ['allowed' => false, 'code' => $code, 'host' => $host, 'normalized_url' => null];
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function normalize(array $parts, string $scheme, string $host): string
    {
        $url = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= $parts['path'] ?? '/';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }

    /*
     * Decimal (2130706433), octal (0177.0.0.1) and hexadecimal (0x7f000001)
     * spellings resolve to addresses in browsers without ever matching
     * FILTER_VALIDATE_IP. A registrable name never ends in an all-digit label.
     */
    private function looksNumeric(string $host): bool
    {
        return preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/', $host) === 1
            || preg_match('/(?:^|\.)[0-9]+$/', $host) === 1;
    }

    private function isPrivateName(string $host): bool
    {
        if (in_array($host, self::PRIVATE_HOSTS, true)) {
            return true;
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateAddress(string $address): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            return true;
        }

        $ranges = strlen($packed) === 4 ? self::PRIVATE_V4 : self::PRIVATE_V6;

        foreach ($ranges as $range) {
            if ($this->inRange($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);

        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;

        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }

    /*
     * Deterministic confusable folding: after lowercasing, the substitutions
     * an eye misses most (0/o, 1/l, rn/m) are collapsed before comparison.
     * Anything fancier belongs to the Worker's navigation-time checks.
     */
    private function fold(string $host): string
    {
        return strtr(str_replace('rn', 'm', $host), ['0' => 'o', '1' => 'l']);
    }
}
