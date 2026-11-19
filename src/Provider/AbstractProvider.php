<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

/**
 * Sensible defaults for adapters: code-point counting, no extra join
 * support, free-text voice ids, billed calls.
 */
abstract class AbstractProvider implements SpeechProvider
{
    public function countChars(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    public function canJoin(string $format): bool
    {
        return false;
    }

    public function voices(string $locale): array
    {
        return [];
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function isBilled(): bool
    {
        return true;
    }
}
