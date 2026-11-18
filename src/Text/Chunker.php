<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Text;

/**
 * Splits spoken text into chunks no longer than a provider's limit.
 *
 * Boundaries, in order of preference: paragraph ends, sentence ends, commas
 * and semicolons, spaces. A word is split only when it alone is longer than
 * the limit, and then only between grapheme clusters.
 *
 * Chunk boundaries are content-defined: after a paragraph the chunk closes
 * either because the next paragraph would not fit, or because a hash of the
 * paragraph says so once the chunk is a quarter full. An edit therefore
 * moves at most the boundaries next to it, and later chunks keep their text
 * and their cache entries.
 */
final class Chunker
{
    /** @var callable(string): int */
    private $count;

    /**
     * @param callable(string): int|null $count Billing unit counter; code points by default.
     */
    public function __construct(private readonly int $maxChars, ?callable $count = null)
    {
        if ($maxChars < 1) {
            throw new \InvalidArgumentException('maxChars must be at least 1.');
        }
        $this->count = $count ?? static fn (string $text): int => mb_strlen($text, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $units = [];
        foreach (explode("\n", $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            foreach ($this->fit($paragraph, 0) as $piece) {
                $units[] = $piece;
            }
        }
        $chunks = [];
        $current = '';
        foreach ($units as $unit) {
            if ($current !== '' && ($this->count)($current . "\n" . $unit) > $this->maxChars) {
                $chunks[] = $current;
                $current = '';
            }
            $current = $current === '' ? $unit : $current . "\n" . $unit;
            if (($this->count)($current) * 4 >= $this->maxChars && crc32($unit) % 3 === 0) {
                $chunks[] = $current;
                $current = '';
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Split a paragraph into pieces that each fit.
     *
     * @return list<string>
     */
    private function fit(string $text, int $level): array
    {
        if (($this->count)($text) <= $this->maxChars) {
            return [$text];
        }
        $pieces = $this->split($text, $level);
        if (count($pieces) <= 1) {
            return $level < 3 ? $this->fit($text, $level + 1) : $this->graphemes($text);
        }
        $out = [];
        $current = '';
        foreach ($pieces as $piece) {
            if (($this->count)($piece) > $this->maxChars) {
                if ($current !== '') {
                    $out[] = $current;
                    $current = '';
                }
                foreach ($this->fit($piece, $level + 1) as $sub) {
                    $out[] = $sub;
                }
                continue;
            }
            $candidate = $current === '' ? $piece : $current . ' ' . $piece;
            if (($this->count)($candidate) > $this->maxChars) {
                $out[] = $current;
                $current = $piece;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function split(string $text, int $level): array
    {
        $pattern = match ($level) {
            0 => '/(?<=[.!?\x{2026}\x{0964}\x{0965}\x{061F}\x{06D4}\x{3002}\x{FF01}\x{FF1F}])["\'\x{2019}\x{201D}\x{00BB})\]]*\s+/u',
            1 => '/(?<=[,;\x{060C}\x{061B}\x{3001}\x{FF0C}\x{FF1B}])\s+/u',
            default => '/\s+/u',
        };
        if ($level > 2) {
            return [$text];
        }
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [$text];
        }

        return array_values(array_map('trim', $parts));
    }

    /**
     * Last resort for a single "word" longer than the limit.
     *
     * @return list<string>
     */
    private function graphemes(string $text): array
    {
        if (preg_match_all('/\X/u', $text, $matches) === false) {
            return [$text];
        }
        $out = [];
        $current = '';
        foreach ($matches[0] as $grapheme) {
            if ($current !== '' && ($this->count)($current . $grapheme) > $this->maxChars) {
                $out[] = $current;
                $current = '';
            }
            $current .= $grapheme;
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }
}
