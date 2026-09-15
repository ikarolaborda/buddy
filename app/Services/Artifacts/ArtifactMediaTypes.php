<?php

namespace App\Services\Artifacts;

/*
 * The declared media type is a claim; the leading bytes are the evidence.
 * Only these formats are accepted (plan §9), and HTML, SVG and executables
 * stay out because the declared list never grows by accident.
 */
final class ArtifactMediaTypes
{
    public const TEXT = 'text/plain';

    public const JSON = 'application/json';

    public const PNG = 'image/png';

    public const JPEG = 'image/jpeg';

    public const GZIP = 'application/gzip';

    public const ZIP = 'application/zip';

    public const ALLOWED = [
        self::TEXT,
        self::JSON,
        self::PNG,
        self::JPEG,
        self::GZIP,
        self::ZIP,
    ];

    public const SNIFF_BYTES = 65536;

    public static function normalize(string $mediaType): ?string
    {
        $type = strtolower(trim(explode(';', $mediaType, 2)[0]));

        $type = match ($type) {
            'application/x-gzip' => self::GZIP,
            'application/x-zip-compressed' => self::ZIP,
            default => $type,
        };

        return in_array($type, self::ALLOWED, true) ? $type : null;
    }

    public static function extension(string $type): string
    {
        return match ($type) {
            self::TEXT => 'txt',
            self::JSON => 'json',
            self::PNG => 'png',
            self::JPEG => 'jpg',
            self::GZIP => 'gz',
            self::ZIP => 'zip',
            default => 'bin',
        };
    }

    public static function isImage(string $type): bool
    {
        return $type === self::PNG || $type === self::JPEG;
    }

    public static function isArchive(string $type): bool
    {
        return $type === self::GZIP || $type === self::ZIP;
    }

    public static function isText(string $type): bool
    {
        return $type === self::TEXT || $type === self::JSON;
    }

    public static function matches(string $type, string $leading, int $totalSize): bool
    {
        return match ($type) {
            self::PNG => str_starts_with($leading, "\x89PNG\r\n\x1a\n"),
            self::JPEG => str_starts_with($leading, "\xFF\xD8\xFF"),
            self::GZIP => str_starts_with($leading, "\x1F\x8B"),
            self::ZIP => str_starts_with($leading, "PK\x03\x04"),
            self::TEXT => self::looksLikeText($leading, $totalSize),
            self::JSON => self::looksLikeJson($leading, $totalSize),
            default => false,
        };
    }

    /*
     * A chunk boundary can split a multibyte sequence, so a truncated chunk
     * may shed up to three trailing bytes before it is judged.
     */
    public static function utf8Prefix(string $bytes, bool $truncated): ?string
    {
        $attempts = $truncated ? 4 : 1;

        for ($drop = 0; $drop < $attempts; $drop++) {
            $candidate = $drop === 0 ? $bytes : substr($bytes, 0, -$drop);

            if (mb_check_encoding($candidate, 'UTF-8')) {
                return $candidate;
            }
        }

        return null;
    }

    private static function looksLikeText(string $leading, int $totalSize): bool
    {
        if ($leading === '' || str_contains($leading, "\0")) {
            return false;
        }

        return self::utf8Prefix($leading, $totalSize > strlen($leading)) !== null;
    }

    private static function looksLikeJson(string $leading, int $totalSize): bool
    {
        if ($totalSize <= strlen($leading)) {
            return json_validate($leading);
        }

        $text = self::utf8Prefix($leading, true);

        if ($text === null) {
            return false;
        }

        $first = substr(ltrim($text), 0, 1);

        return $first === '{' || $first === '[';
    }
}
