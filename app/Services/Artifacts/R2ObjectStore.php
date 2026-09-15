<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactObjectStore;
use Illuminate\Filesystem\FilesystemAdapter;

final class R2ObjectStore implements ArtifactObjectStore
{
    public function __construct(
        private FilesystemAdapter $disk,
    ) {}

    public function presignUpload(string $key, int $expiresSeconds, string $contentType, int $maxBytes): array
    {
        $signed = $this->disk->temporaryUploadUrl($key, now()->addSeconds($expiresSeconds), [
            'ContentType' => $contentType,
            'ContentLength' => $maxBytes,
        ]);

        $headers = [];

        foreach ($signed['headers'] as $name => $value) {
            // The host is the URL's, and the caller's HTTP client sets it.
            if (strtolower((string) $name) === 'host') {
                continue;
            }

            $headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return [
            'url' => $signed['url'],
            'headers' => $headers + ['Content-Type' => $contentType, 'Content-Length' => (string) $maxBytes],
        ];
    }

    public function head(string $key): ?array
    {
        if (! $this->disk->fileExists($key)) {
            return null;
        }

        $type = $this->disk->mimeType($key);

        return [
            'size' => (int) $this->disk->size($key),
            'content_type' => is_string($type) && $type !== '' ? $type : null,
        ];
    }

    public function readStream(string $key)
    {
        return $this->disk->readStream($key);
    }

    public function copy(string $from, string $to): bool
    {
        return $this->disk->copy($from, $to);
    }

    public function delete(string $key): bool
    {
        return $this->disk->delete($key);
    }

    public function presignDownload(string $key, int $expiresSeconds, string $filename, string $contentType): string
    {
        return $this->disk->temporaryUrl($key, now()->addSeconds($expiresSeconds), [
            'ResponseContentType' => $contentType,
            'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
