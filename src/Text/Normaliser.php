<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Text;

/**
 * Turns extracted paragraphs into the spoken text.
 *
 * Deterministic: the same input gives the same output on every request and
 * every supported PHP version. VERSION changes whenever the output for any
 * input could change.
 */
final class Normaliser
{
    public const VERSION = 1;

    private const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B50}\x{2B55}'
        . '\x{FE0E}\x{FE0F}\x{20E3}\x{E0020}-\x{E007F}\x{3030}\x{303D}\x{3297}\x{3299}]/u';

    private const ZERO_WIDTH = '/[\x{200B}\x{2060}\x{FEFF}\x{00AD}\x{180E}\x{200E}\x{200F}]/u';

    /** ZWNJ and ZWJ are kept only between two letters or marks (Indic shaping). */
    private const LONE_JOINER = '/(?<![\p{L}\p{M}])[\x{200C}\x{200D}]|[\x{200C}\x{200D}](?![\p{L}\p{M}])/u';

    private const URL = '~(?:\bhttps?://|\bwww\.)[^\s<>"]+~iu';

    private const EMAIL = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u';

    private const SPACE = '/[\s\p{Z}]+/u';

    /** Characters that already end a sentence. */
    private const TERMINAL = '/[.!?;:\x{2026}\x{0964}\x{0965}\x{061F}\x{06D4}\x{3002}\x{FF01}\x{FF1F}\x{FF0E}]$/u';

    /** Closing quotes and brackets that may follow terminal punctuation. */
    private const CLOSERS = '/[\x{0022}\x{0027}\x{2019}\x{201D}\x{00BB}\x{203A})\]]+$/u';

    /**
     * The version string stored with each hash. It also says whether NFC
     * normalisation was available, because output differs without it.
     */
    public static function version(): string
    {
        return self::VERSION . (class_exists(\Normalizer::class) ? '' : '-nonfc');
    }

    /**
     * @param list<string> $paragraphs
     */
    public function normalise(array $paragraphs): string
    {
        $out = [];
        foreach ($paragraphs as $paragraph) {
            $clean = $this->sentence($paragraph);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return implode("\n", $out);
    }

    /** Normalise one paragraph into one or more sentences on one line. */
    public function sentence(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalised)) {
                $text = $normalised;
            }
        }
        $text = (string) preg_replace(self::URL, ' ', $text);
        $text = (string) preg_replace(self::EMAIL, ' ', $text);
        $text = (string) preg_replace(self::EMOJI, '', $text);
        $text = (string) preg_replace(self::ZERO_WIDTH, '', $text);
        $text = (string) preg_replace(self::LONE_JOINER, '', $text);
        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $text);
        $text = trim((string) preg_replace(self::SPACE, ' ', $text));
        // Punctuation left dangling by a removed URL ("see ." or "( )").
        $text = trim((string) preg_replace('/\s+([,.;:!?])/u', '$1', $text));
        $text = trim((string) preg_replace('/\(\s*\)|\[\s*\]/u', '', $text));
        $text = trim((string) preg_replace('/\s{2,}/u', ' ', $text));
        if ($text === '' || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return '';
        }

        return $this->terminate($text);
    }

    private function terminate(string $text): string
    {
        $core = (string) preg_replace(self::CLOSERS, '', $text);
        if ($core !== '' && preg_match(self::TERMINAL, $core) === 1) {
            return $text;
        }

        return $text . '.';
    }
}
