<?php

namespace App\Contracts;

/*
 * The only door to artifact bytes. Keys are opaque server-issued paths; a key
 * never proves ownership, and clients only ever receive signed URLs for keys
 * this application chose.
 */
interface ArtifactObjectStore
{
    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignUpload(string $key, int $expiresSeconds, string $contentType, int $maxBytes): array;

    /**
     * @return array{size: int, content_type: ?string}|null
     */
    public function head(string $key): ?array;

    /**
     * @return resource|null
     */
    public function readStream(string $key);

    public function copy(string $from, string $to): bool;

    public function delete(string $key): bool;

    public function presignDownload(string $key, int $expiresSeconds, string $filename, string $contentType): string;
}
