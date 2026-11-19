<?php

/**
 * Example: register your own speech adapter. Put this in a small plugin.
 *
 * The adapter talks to a hypothetical self-hosted engine that takes JSON
 * and returns mp3 bytes. Use the plugin's exceptions so failures are
 * classified correctly.
 */

declare(strict_types=1);

use IndianOpinion\ReadAloud\Audio\AudioFormat;
use IndianOpinion\ReadAloud\Exception\AccountException;
use IndianOpinion\ReadAloud\Exception\TransientProviderException;
use IndianOpinion\ReadAloud\Provider\AbstractProvider;
use IndianOpinion\ReadAloud\Provider\SpeechAudio;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Support\TempFiles;

final class ExampleEngineProvider extends AbstractProvider
{
    public function __construct(private readonly string $endpoint)
    {
    }

    public function id(): string
    {
        return 'example-engine';
    }

    public function label(): string
    {
        return 'Example engine';
    }

    public function maxChars(): int
    {
        return 3000;
    }

    public function formats(): array
    {
        return ['mp3'];
    }

    public function synthesize(SpeechRequest $request): SpeechAudio
    {
        $response = wp_remote_post($this->endpoint, [
            'timeout' => $request->timeoutSeconds,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode(['text' => $request->text, 'voice' => $request->voice]),
        ]);
        if (is_wp_error($response)) {
            throw new TransientProviderException($response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 || $status === 403) {
            throw new AccountException('The engine refused the credentials.', $status);
        }
        if ($status !== 200) {
            throw new TransientProviderException('HTTP ' . $status, null, $status);
        }
        $path = TempFiles::create('mp3');
        file_put_contents($path, wp_remote_retrieve_body($response));

        return new SpeechAudio($path, 'mp3', AudioFormat::mime('mp3'));
    }
}

add_filter('read_aloud_providers', static function (array $providers): array {
    $providers['example-engine'] = static fn (array $settings) => new ExampleEngineProvider('https://tts.example.internal/speak');

    return $providers;
});

// The engine runs on the local network, so allow its private address.
add_filter('read_aloud_allow_local_endpoint', static fn (bool $allow, string $url): bool => $allow || str_starts_with($url, 'https://tts.example.internal/'), 10, 2);
