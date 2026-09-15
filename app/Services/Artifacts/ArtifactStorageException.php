<?php

namespace App\Services\Artifacts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/*
 * Stable, machine-readable failure codes for the artifact routes. The
 * framework renders it through render(), and report() is a no-op because
 * every case is an expected client or dependency outcome, not a defect.
 */
final class ArtifactStorageException extends RuntimeException
{
    public const QUOTA_EXHAUSTED = 'quota_exhausted';

    public const UPLOAD_TOO_LARGE = 'upload_too_large';

    public const UNSUPPORTED_MEDIA_TYPE = 'unsupported_media_type';

    public const UPLOAD_FAILED = 'upload_failed';

    public const RESERVATION_EXPIRED = 'reservation_expired';

    public const OBJECT_MISSING = 'object_missing';

    public const PROCESSING_PENDING = 'processing_pending';

    public const DEPENDENCY_UNAVAILABLE = 'dependency_unavailable';

    public const NOT_FOUND = 'not_found';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $context = [],
    ) {
        parent::__construct($errorCode);
    }

    public static function quotaExhausted(string $limit, int $max): self
    {
        return new self(self::QUOTA_EXHAUSTED, 422, ['limit' => $limit, 'max' => $max]);
    }

    public static function uploadTooLarge(int $maxBytes): self
    {
        return new self(self::UPLOAD_TOO_LARGE, 422, ['max_bytes' => $maxBytes]);
    }

    public static function unsupportedMediaType(): self
    {
        return new self(self::UNSUPPORTED_MEDIA_TYPE, 422, ['allowed' => ArtifactMediaTypes::ALLOWED]);
    }

    public static function uploadFailed(string $reason): self
    {
        return new self(self::UPLOAD_FAILED, 422, ['reason' => $reason]);
    }

    public static function reservationExpired(): self
    {
        return new self(self::RESERVATION_EXPIRED, 410);
    }

    public static function objectMissing(): self
    {
        return new self(self::OBJECT_MISSING, 409);
    }

    public static function processingPending(): self
    {
        return new self(self::PROCESSING_PENDING, 409);
    }

    public static function dependencyUnavailable(): self
    {
        return new self(self::DEPENDENCY_UNAVAILABLE, 503);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 404);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => $this->errorCode] + $this->context, $this->status);
    }

    public function report(): void {}
}
