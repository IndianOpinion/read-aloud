<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Text;

/**
 * Pronunciation lexicon: whole-word replacements applied at synthesis time.
 *
 * Word boundaries are Unicode-aware: a letter, mark or digit on either side
 * means the match is inside a longer word and is left alone. This holds for
 * scripts such as Devanagari where vowel signs are combining marks.
 */
final class Lexicon
{
    /** @var list<array{written: string, spoken: string, case_sensitive: bool}> */
    private array $entries;

    /**
     * @param list<array{written: string, spoken: string, case_sensitive?: bool}> $entries
     */
    public function __construct(array $entries)
    {
        $clean = [];
        foreach ($entries as $entry) {
            $written = trim($entry['written']);
            if ($written === '') {
                continue;
            }
            $clean[] = [
                'written' => $written,
                'spoken' => trim($entry['spoken']),
                'case_sensitive' => (bool) ($entry['case_sensitive'] ?? true),
            ];
        }
        usort($clean, static fn (array $a, array $b): int => mb_strlen($b['written']) <=> mb_strlen($a['written']));
        $this->entries = $clean;
    }

    /** Changes whenever any entry changes; part of the render hash. */
    public function version(): string
    {
        if ($this->entries === []) {
            return '0';
        }

        return substr(hash('sha256', (string) json_encode($this->entries, JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    public function apply(string $text): string
    {
        if ($this->entries === [] || $text === '') {
            return $text;
        }
        $alternatives = [];
        foreach ($this->entries as $entry) {
            $quoted = preg_quote($entry['written'], '/');
            $alternatives[] = '(' . ($entry['case_sensitive'] ? $quoted : '(?i:' . $quoted . ')') . ')';
        }
        $pattern = '/(?<![\p{L}\p{M}\p{N}_])(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{M}\p{N}_])/u';
        $entries = $this->entries;
        $result = preg_replace_callback($pattern, static function (array $m) use ($entries): string {
            $count = count($m);
            for ($i = 1; $i < $count; $i++) {
                if ($m[$i] !== '') {
                    return $entries[$i - 1]['spoken'];
                }
            }
            return $m[0];
        }, $text);

        return is_string($result) ? $result : $text;
    }

    /**
     * Parse the settings textarea format: one "written = spoken" per line,
     * with a trailing " (i)" for a case-insensitive entry.
     *
     * @return list<array{written: string, spoken: string, case_sensitive: bool}>
     */
    public static function parse(string $lines): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$written, $spoken] = array_map('trim', explode('=', $line, 2));
            $caseSensitive = true;
            if (preg_match('/\s*\(i\)$/', $spoken) === 1) {
                $spoken = trim((string) preg_replace('/\s*\(i\)$/', '', $spoken));
                $caseSensitive = false;
            }
            if ($written !== '' && $spoken !== '') {
                $out[] = ['written' => $written, 'spoken' => $spoken, 'case_sensitive' => $caseSensitive];
            }
        }

        return $out;
    }
}
