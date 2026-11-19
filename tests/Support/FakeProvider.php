<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests\Support;

use IndianOpinion\ReadAloud\Audio\AudioFormat;
use IndianOpinion\ReadAloud\Audio\Mp3;
use IndianOpinion\ReadAloud\Audio\Wav;
use IndianOpinion\ReadAloud\Provider\AbstractProvider;
use IndianOpinion\ReadAloud\Provider\SpeechAudio;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Support\TempFiles;

/**
 * Scriptable provider. Each call takes the next scripted behaviour, or
 * makes valid audio when the script is empty.
 */
final class FakeProvider extends AbstractProvider
{
    /** @var list<SpeechRequest> */
    public array $requests = [];
    /** @var list<callable(SpeechRequest): SpeechAudio|\Throwable|null> */
    public array $script = [];
    /** @var list<string> */
    public array $formatList = ['mp3', 'wav', 'ogg'];
    public bool $joinable = false;
    public bool $billed = true;
    public ?int $billedOverride = null;
    public ?float $duration = null;
    /** @var callable(SpeechRequest): void|null */
    public $onCall = null;

    public function __construct(public int $max = 1000)
    {
    }

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake provider';
    }

    public function maxChars(): int
    {
        return $this->max;
    }

    public function formats(): array
    {
        return $this->formatList;
    }

    public function canJoin(string $format): bool
    {
        return $this->joinable;
    }

    public function isBilled(): bool
    {
        return $this->billed;
    }

    public function synthesize(SpeechRequest $request): SpeechAudio
    {
        $this->requests[] = $request;
        if ($this->onCall !== null) {
            ($this->onCall)($request);
        }
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (is_callable($next)) {
            $result = $next($request);
            if ($result instanceof SpeechAudio) {
                return $result;
            }
        }

        return self::audio($request, $this->billedOverride, $this->duration);
    }

    /** Valid audio for a request, about one second per 15 characters. */
    public static function audio(SpeechRequest $request, ?int $billed = null, ?float $duration = null): SpeechAudio
    {
        $seconds = max(1.0, mb_strlen($request->text) / 15);
        $bytes = match ($request->format) {
            'wav' => Wav::silence($seconds),
            'ogg' => 'OggS' . str_repeat("\0", (int) ($seconds * 4000)),
            default => Mp3::silence($seconds),
        };
        $path = TempFiles::create($request->format);
        file_put_contents($path, $bytes);

        return new SpeechAudio($path, $request->format, AudioFormat::mime($request->format), $billed, $duration);
    }

    /** A response whose file holds arbitrary bytes. */
    public static function bytes(SpeechRequest $request, string $bytes, ?string $format = null): SpeechAudio
    {
        $format ??= $request->format;
        $path = TempFiles::create($format);
        file_put_contents($path, $bytes);

        return new SpeechAudio($path, $format, AudioFormat::mime($format));
    }
}
