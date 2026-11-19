<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

/**
 * The result of one provider call: a temporary file the adapter wrote.
 */
final class SpeechAudio
{
    public function __construct(
        public readonly string $path,
        public readonly string $format,
        public readonly string $mimeType,
        public readonly ?int $billedChars = null,
        public readonly ?float $durationSeconds = null,
        public readonly ?string $providerRequestId = null
    ) {
    }
}
