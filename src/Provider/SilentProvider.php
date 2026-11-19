<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

use IndianOpinion\ReadAloud\Audio\AudioFormat;
use IndianOpinion\ReadAloud\Audio\Mp3;
use IndianOpinion\ReadAloud\Audio\Wav;
use IndianOpinion\ReadAloud\Exception\ProviderConfigException;
use IndianOpinion\ReadAloud\Exception\StorageException;
use IndianOpinion\ReadAloud\Support\TempFiles;

/**
 * No-network provider that returns valid silent audio, about one second per
 * fifteen characters. For staging, development and tests. It costs nothing
 * and is not counted against the money cap, but counts against the
 * character and generation caps so cap behaviour can be tried safely.
 */
final class SilentProvider extends AbstractProvider
{
    public const CHARS_PER_SECOND = 15.0;

    public function __construct(private readonly int $maxChars = 5000)
    {
    }

    public function id(): string
    {
        return 'silent';
    }

    public function label(): string
    {
        return 'Silent (no network, for testing)';
    }

    public function maxChars(): int
    {
        return $this->maxChars;
    }

    public function formats(): array
    {
        return [AudioFormat::MP3, AudioFormat::WAV];
    }

    public function isBilled(): bool
    {
        return false;
    }

    public function synthesize(SpeechRequest $request): SpeechAudio
    {
        $seconds = max(1.0, $this->countChars($request->text) / self::CHARS_PER_SECOND) / $request->speed;
        if ($request->format === AudioFormat::MP3) {
            $bytes = Mp3::silence($seconds);
        } elseif ($request->format === AudioFormat::WAV) {
            $bytes = Wav::silence($seconds);
        } else {
            throw new ProviderConfigException('The silent provider makes mp3 or wav only.');
        }
        $path = TempFiles::create($request->format);
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            TempFiles::remove($path);
            throw new StorageException('Cannot write silent audio.');
        }

        return new SpeechAudio(
            $path,
            $request->format,
            AudioFormat::mime($request->format),
            $this->countChars($request->text),
            $seconds,
            $request->requestId
        );
    }
}
