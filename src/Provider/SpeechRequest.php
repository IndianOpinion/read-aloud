<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

/**
 * One chunk of text to synthesise. Immutable.
 */
final class SpeechRequest
{
    public readonly float $speed;

    public function __construct(
        public readonly string $text,
        public readonly string $voice,
        public readonly string $model,
        public readonly string $locale,
        public readonly string $format,
        float $speed,
        public readonly string $requestId,
        public readonly int $timeoutSeconds = 60
    ) {
        if ($speed < 0.5 || $speed > 2.0) {
            throw new \InvalidArgumentException('Speed must be between 0.5 and 2.0.');
        }
        $this->speed = $speed;
    }

    /** A random version 4 UUID for request ids. */
    public static function newRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
