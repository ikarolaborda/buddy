<?php

namespace App\Services\Edge;

use RuntimeException;

/*
 * Bearer-style tokens the Worker verifies with the shared service key, so
 * Azure can hand clients upload and download URLs without ever holding an
 * R2 credential. A token is `<kind>.<payload>.<signature>`; the signature
 * covers the encoded payload segment as transmitted, which keeps the Worker
 * free of any JSON canonicalization concerns.
 */
final class EdgeTokenSigner
{
    public const KIND_UPLOAD = 'bup1';

    public const KIND_DOWNLOAD = 'bdl1';

    public function mintUpload(string $key, int $maxBytes, string $contentType, int $ttlSeconds, string $uploadId): string
    {
        return $this->mint(self::KIND_UPLOAD, [
            'key' => $key,
            'max_bytes' => $maxBytes,
            'content_type' => $contentType,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
            'upload_id' => $uploadId,
        ]);
    }

    public function mintDownload(string $key, string $filename, string $contentType, int $ttlSeconds): string
    {
        return $this->mint(self::KIND_DOWNLOAD, [
            'key' => $key,
            'filename' => $filename,
            'content_type' => $contentType,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $token, string $expectedKind): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$kind, $encoded, $signature] = $parts;

        if ($kind !== $expectedKind) {
            return null;
        }

        if (! hash_equals($this->encode($this->sign($encoded)), $signature)) {
            return null;
        }

        $payload = json_decode((string) $this->decode($encoded), true);

        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['exp'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mint(string $kind, array $payload): string
    {
        $encoded = $this->encode((string) json_encode($payload));

        return $kind.'.'.$encoded.'.'.$this->encode($this->sign($encoded));
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, $this->secret(), true);
    }

    private function secret(): string
    {
        $key = (string) config('buddy.edge.service_key');

        if ($key === '') {
            throw new RuntimeException('Edge service key is not configured.');
        }

        return $key;
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
