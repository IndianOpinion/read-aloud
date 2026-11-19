<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

use IndianOpinion\ReadAloud\Exception\ReadAloudException;

/**
 * Adapter for one text-to-speech API.
 *
 * An adapter must throw one of the plugin's exceptions from synthesize().
 * Any other throwable is treated as transient once, then as permanent.
 */
interface SpeechProvider
{
    /** Stable slug matching ^[a-z0-9-]{1,32}$. */
    public function id(): string;

    /** Human name for the settings screen. */
    public function label(): string;

    /** Largest input per request, in the unit of countChars(). */
    public function maxChars(): int;

    /** Billing unit count of a text. */
    public function countChars(string $text): int;

    /** @return list<string> Any of mp3, wav, ogg. */
    public function formats(): array;

    /** Whether the plugin may byte-join chunks of this format. */
    public function canJoin(string $format): bool;

    /** @return list<Voice> Known voices; empty means a free-text voice id. */
    public function voices(string $locale): array;

    /**
     * Field definitions for the settings screen.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, secret: bool, help?: string, options?: array<string, string>, default?: string}>
     */
    public function settingsFields(): array;

    /**
     * @param array<string, mixed> $settings
     * @return list<string> Error messages; empty when valid.
     */
    public function validateSettings(array $settings): array;

    /**
     * Synthesise one chunk.
     *
     * @throws ReadAloudException
     */
    public function synthesize(SpeechRequest $request): SpeechAudio;

    /** Whether calls to this provider cost money (counts against the money cap). */
    public function isBilled(): bool;
}
