<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Jobs;

/**
 * What would be sent for a post right now, and whether it may be.
 */
final class Plan
{
    /**
     * @param array{locale: string, voice: string, model: string, speed: float, format: string}|null $voice
     * @param array<string, scalar|null> $render
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly string $reason,
        public readonly string $text = '',
        public readonly string $contentHash = '',
        public readonly string $locale = '',
        public readonly ?array $voice = null,
        public readonly string $renderHash = '',
        public readonly array $render = [],
        public readonly int $chars = 0
    ) {
    }

    /** Skip reasons that come from the text, not from opt-in. */
    public function isSkip(): bool
    {
        return in_array($this->reason, ['too_short', 'too_long', 'no_voice'], true);
    }
}
