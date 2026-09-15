<?php

namespace Tests\Support;

use App\Contracts\ArtifactObjectStore;
use RuntimeException;

/*
 * In-memory stand-in for R2. Tests PUT bytes with put() the way a client
 * would use a signed URL, and flip $unavailable or $failDeletes to rehearse
 * dependency failures without any network.
 */
final class FakeArtifactObjectStore implements ArtifactObjectStore
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<array<string, mixed>> */
    public array $signed = [];

    public bool $unavailable = false;

    public bool $failDeletes = false;

    public function put(string $key, string $bytes): void
    {
        $this->objects[$key] = $bytes;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->objects);
    }

    public function get(string $key): ?string
    {
        return $this->objects[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->objects);
    }

    public function presignUpload(string $key, int $expiresSeconds, string $contentType, int $maxBytes): array
    {
        $this->guard();
        $this->signed[] = ['op' => 'upload', 'key' => $key, 'expires' => $expiresSeconds, 'content_type' => $contentType, 'max_bytes' => $maxBytes];

        return [
            'url' => 'https://r2.fake/'.$key.'?X-Amz-Expires='.$expiresSeconds.'&X-Amz-Signature=fake',
            'headers' => ['Content-Type' => $contentType, 'Content-Length' => (string) $maxBytes],
        ];
    }

    public function head(string $key): ?array
    {
        $this->guard();

        if (! $this->has($key)) {
            return null;
        }

        return ['size' => strlen($this->objects[$key]), 'content_type' => null];
    }

    public function readStream(string $key)
    {
        $this->guard();

        if (! $this->has($key)) {
            return null;
        }

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->objects[$key]);
        rewind($stream);

        return $stream;
    }

    public function copy(string $from, string $to): bool
    {
        $this->guard();

        if (! $this->has($from)) {
            return false;
        }

        $this->objects[$to] = $this->objects[$from];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->guard();

        if ($this->failDeletes) {
            return false;
        }

        unset($this->objects[$key]);

        return true;
    }

    public function presignDownload(string $key, int $expiresSeconds, string $filename, string $contentType): string
    {
        $this->guard();
        $this->signed[] = ['op' => 'download', 'key' => $key, 'expires' => $expiresSeconds, 'filename' => $filename, 'content_type' => $contentType];

        return 'https://r2.fake/'.$key
            .'?response-content-type='.rawurlencode($contentType)
            .'&response-content-disposition='.rawurlencode('attachment; filename="'.$filename.'"')
            .'&X-Amz-Expires='.$expiresSeconds.'&X-Amz-Signature=fake';
    }

    private function guard(): void
    {
        if ($this->unavailable) {
            throw new RuntimeException('R2 unavailable');
        }
    }
}
