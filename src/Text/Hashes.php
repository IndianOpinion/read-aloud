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

    /**
     * First 12 hex characters of SHA-256 of content hash plus render hash.
     *
     * With a site secret the digest is keyed (HMAC), so nobody who knows the
     * published text and the voice settings can work out the URL of a file
     * that still waits for review.
     */
    public static function fingerprint(string $contentHash, string $renderHash, string $secret = ''): string
    {
        $data = $contentHash . $renderHash;

        return substr($secret === '' ? hash('sha256', $data) : hash_hmac('sha256', $data, $secret), 0, 12);
    }

    /** Chunk cache name; keyed with the site secret for the same reason. */
    public static function chunk(string $chunkText, string $renderHash, string $secret = ''): string
    {
        $data = $renderHash . "\n" . $chunkText;

        return $secret === '' ? hash('sha256', $data) : hash_hmac('sha256', $data, $secret);
    }
}
