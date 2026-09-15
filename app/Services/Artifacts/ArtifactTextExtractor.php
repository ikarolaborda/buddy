<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactObjectStore;
use App\Models\BuddyArtifact;
use App\Services\Interventions\InterventionContext;
use Throwable;
use ZipArchive;

/*
 * Bounded text for the council packet (plan §9): at most artifact_chars of
 * redacted UTF-8, read from at most 2 MiB of the object. Archives are
 * expanded under a ratio and size ceiling so a bomb fails fast and visibly.
 * Large storage never implies large model context.
 */
final class ArtifactTextExtractor
{
    public const MAX_READ_BYTES = 2097152;

    public const MAX_EXPANDED_BYTES = 8388608;

    public const MAX_EXPANSION_RATIO = 20;

    public const MAX_ARCHIVE_ENTRIES = 1000;

    private const CHUNK = 65536;

    public function __construct(
        private InterventionContext $redactor,
    ) {}

    public function extract(ArtifactObjectStore $store, BuddyArtifact $artifact): string
    {
        $type = (string) $artifact->media_type;
        $size = (int) $artifact->size_bytes;

        if (ArtifactMediaTypes::isImage($type)) {
            return sprintf('%s, %d bytes', $type, $size);
        }

        $stream = $store->readStream((string) $artifact->object_key);

        if (! is_resource($stream)) {
            throw new ArtifactProcessingException('object_missing');
        }

        try {
            return match ($type) {
                ArtifactMediaTypes::TEXT, ArtifactMediaTypes::JSON => $this->fromText($stream),
                ArtifactMediaTypes::GZIP => $this->fromGzip($stream, $size),
                ArtifactMediaTypes::ZIP => $this->fromZip($stream, $size),
                default => throw new ArtifactProcessingException('unsupported_media_type'),
            };
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function limit(): int
    {
        return (int) config('buddy_agents.council.artifact_chars', 16000);
    }

    /**
     * @param  resource  $stream
     */
    private function fromText($stream): string
    {
        [$bytes, $truncated] = $this->read($stream, self::MAX_READ_BYTES);

        return $this->bound($this->text($bytes, $truncated));
    }

    /**
     * @param  resource  $stream
     */
    private function fromGzip($stream, int $compressedSize): string
    {
        $budget = $this->expansionBudget($compressedSize);
        $filter = stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 47]);

        if ($filter === false) {
            throw new ArtifactProcessingException('archive_invalid');
        }

        $expanded = 0;
        $collected = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $expanded += strlen($chunk);

                if ($expanded > $budget) {
                    throw new ArtifactProcessingException('archive_expansion_limit');
                }

                if (strlen($collected) < self::MAX_READ_BYTES) {
                    $collected .= substr($chunk, 0, self::MAX_READ_BYTES - strlen($collected));
                }
            }
        } catch (ArtifactProcessingException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ArtifactProcessingException('archive_invalid');
        }

        if ($expanded === 0) {
            throw new ArtifactProcessingException('archive_invalid');
        }

        if (str_contains($collected, "\0") || ArtifactMediaTypes::utf8Prefix($collected, $expanded > strlen($collected)) === null) {
            return sprintf('%s, %d bytes, expanded %d bytes, binary payload', ArtifactMediaTypes::GZIP, $compressedSize, $expanded);
        }

        return $this->bound($this->text($collected, $expanded > strlen($collected)));
    }

    /**
     * @param  resource  $stream
     */
    private function fromZip($stream, int $compressedSize): string
    {
        $path = tempnam(sys_get_temp_dir(), 'buddy-artifact-');

        if ($path === false) {
            throw new ArtifactProcessingException('archive_invalid');
        }

        $zip = new ZipArchive;

        try {
            $file = fopen($path, 'wb');

            if ($file === false) {
                throw new ArtifactProcessingException('archive_invalid');
            }

            stream_copy_to_stream($stream, $file, max($compressedSize, 1));
            fclose($file);

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new ArtifactProcessingException('archive_invalid');
            }

            return $this->bound($this->zipText($zip, $compressedSize));
        } finally {
            if ($zip->filename !== '') {
                $zip->close();
            }

            @unlink($path);
        }
    }

    private function zipText(ZipArchive $zip, int $compressedSize): string
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new ArtifactProcessingException('archive_entry_limit');
        }

        $budget = $this->expansionBudget($compressedSize);
        $declared = 0;
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw new ArtifactProcessingException('archive_invalid');
            }

            if ((int) ($stat['encryption_method'] ?? 0) !== ZipArchive::EM_NONE) {
                throw new ArtifactProcessingException('archive_encrypted');
            }

            $declared += (int) $stat['size'];

            if ($declared > $budget) {
                throw new ArtifactProcessingException('archive_expansion_limit');
            }

            $entries[] = $stat;
        }

        $limit = $this->limit();
        $read = 0;
        $out = '';

        foreach ($entries as $stat) {
            if (mb_strlen($out) >= $limit) {
                break;
            }

            $name = (string) $stat['name'];

            if (str_ends_with($name, '/')) {
                continue;
            }

            $entry = $zip->getStreamIndex((int) $stat['index']);

            if ($entry === false) {
                throw new ArtifactProcessingException('archive_invalid');
            }

            // Central-directory sizes are claims; the bytes actually read are
            // what count against the budget.
            [$bytes, $truncated] = $this->read($entry, min(self::MAX_READ_BYTES, $budget - $read + 1));
            fclose($entry);
            $read += strlen($bytes);

            if ($read > $budget) {
                throw new ArtifactProcessingException('archive_expansion_limit');
            }

            $out .= sprintf("== %s (%d bytes) ==\n", $name, (int) $stat['size']);
            $text = str_contains($bytes, "\0") ? null : ArtifactMediaTypes::utf8Prefix($bytes, $truncated);
            $out .= ($text ?? '[binary entry]')."\n\n";
        }

        return $out;
    }

    private function expansionBudget(int $compressedSize): int
    {
        return min(self::MAX_EXPANDED_BYTES, self::MAX_EXPANSION_RATIO * max($compressedSize, 1));
    }

    /**
     * @param  resource  $stream
     * @return array{0: string, 1: bool}
     */
    private function read($stream, int $max): array
    {
        $bytes = '';

        while (strlen($bytes) <= $max && ! feof($stream)) {
            $chunk = fread($stream, min(self::CHUNK, $max + 1 - strlen($bytes)));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $bytes .= $chunk;
        }

        $truncated = strlen($bytes) > $max;

        return [$truncated ? substr($bytes, 0, $max) : $bytes, $truncated];
    }

    private function text(string $bytes, bool $truncated): string
    {
        $text = ArtifactMediaTypes::utf8Prefix($bytes, $truncated);

        if ($text === null) {
            throw new ArtifactProcessingException('invalid_utf8');
        }

        return $text;
    }

    /*
     * Truncate, redact, truncate again: redaction markers can be longer than
     * what they replace, and the bound must hold on what is stored.
     */
    private function bound(string $text): string
    {
        $limit = $this->limit();

        return mb_substr($this->redactor->redact(mb_substr($text, 0, $limit)), 0, $limit);
    }
}
