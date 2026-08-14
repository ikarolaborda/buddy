<?php

namespace App\Services\Knowledge\Extraction\Extractors;

final class MarkdownSection
{
    /**
     * @var array<int, string>
     */
    private array $lines = [];

    public function __construct(
        public readonly string $title,
        public readonly int $line,
    ) {}

    public function append(string $line): void
    {
        $this->lines[] = $line;
    }

    public function isEmpty(): bool
    {
        return trim(implode("\n", $this->lines)) === '';
    }

    /**
     * @return array<int, string>
     */
    public function chunks(int $size): array
    {
        $text = trim(implode("\n", $this->lines));
        $chunks = [];
        $length = mb_strlen($text);

        for ($offset = 0; $offset < $length; $offset += $size) {
            $chunks[] = mb_substr($text, $offset, $size);
        }

        return $chunks;
    }
}
