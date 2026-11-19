<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Audio;

use IndianOpinion\ReadAloud\Exception\InvalidAudioException;

/**
 * Known formats, their MIME types and magic-byte checks.
 */
final class AudioFormat
{
    public const MP3 = 'mp3';
    public const WAV = 'wav';
    public const OGG = 'ogg';

    /** @var array<string, string> */
    private const MIME = [
        self::MP3 => 'audio/mpeg',
        self::WAV => 'audio/wav',
        self::OGG => 'audio/ogg',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MIME);
    }

    public static function isKnown(string $format): bool
    {
        return isset(self::MIME[$format]);
    }

    public static function mime(string $format): string
    {
        if (!isset(self::MIME[$format])) {
            throw new \InvalidArgumentException('Unknown audio format: ' . $format);
        }

        return self::MIME[$format];
    }

    /** Whether the plugin itself can join chunks of this format. */
    public static function pluginCanJoin(string $format): bool
    {
        return $format === self::MP3 || $format === self::WAV;
    }

    /** Whether the first bytes of a file match the format. */
    public static function magicMatches(string $head, string $format): bool
    {
        switch ($format) {
            case self::MP3:
                if (strncmp($head, 'ID3', 3) === 0) {
                    return true;
                }
                return Mp3::parseHeader(substr($head, 0, 4)) !== null;
            case self::WAV:
                return strlen($head) >= 12
                    && strncmp($head, 'RIFF', 4) === 0
                    && substr($head, 8, 4) === 'WAVE';
            case self::OGG:
                return strncmp($head, 'OggS', 4) === 0;
        }

        return false;
    }

    /**
     * Validate a file the provider produced.
     *
     * @throws InvalidAudioException
     */
    public static function validateFile(string $path, string $format, int $inputChars, ?int $minBytes = null): void
    {
        if (!self::isKnown($format)) {
            throw new InvalidAudioException('Unknown format requested.');
        }
        if (!is_file($path)) {
            throw new InvalidAudioException('The provider produced no file.');
        }
        $size = filesize($path);
        if ($size === false) {
            throw new InvalidAudioException('The provider file cannot be read.');
        }
        $minimum = $minBytes ?? self::minimumBytes($inputChars);
        if ($size < $minimum) {
            throw new InvalidAudioException(sprintf('Body of %d bytes is shorter than the minimum of %d.', $size, $minimum));
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidAudioException('The provider file cannot be opened.');
        }
        $head = (string) fread($handle, 16);
        fclose($handle);
        if (!self::magicMatches($head, $format)) {
            throw new InvalidAudioException('The response is not ' . $format . ' audio.');
        }
    }

    /** At least 1 KiB per 100 characters of input. */
    public static function minimumBytes(int $inputChars): int
    {
        return max(1, intdiv(max(0, $inputChars) * 1024, 100));
    }
}
