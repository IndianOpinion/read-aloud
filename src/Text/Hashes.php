<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Text;

/**
 * The three hashes that decide when audio is made again.
 */
final class Hashes
{
    /** SHA-256 of the spoken text plus the normaliser version. */
    public static function content(string $spokenText, ?string $normaliserVersion = null): string
    {
        return hash('sha256', ($normaliserVersion ?? Normaliser::version()) . "\n" . $spokenText);
    }

    /**
     * SHA-256 of the settings that shape the sound.
     *
     * @param array<string, scalar|null> $settings
     */
    public static function render(array $settings): string
    {
        ksort($settings);

        return hash('sha256', (string) json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** First 12 hex characters of SHA-256 of content hash plus render hash. */
    public static function fingerprint(string $contentHash, string $renderHash): string
    {
        return substr(hash('sha256', $contentHash . $renderHash), 0, 12);
    }

    public static function chunk(string $chunkText, string $renderHash): string
    {
        return hash('sha256', $renderHash . "\n" . $chunkText);
    }
}
