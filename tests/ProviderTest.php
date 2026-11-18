<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Admin\SettingsPage;
use IndianOpinion\ReadAloud\Audio\Mp3;
use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\ProviderConfigException;
use IndianOpinion\ReadAloud\Provider\AbstractProvider;
use IndianOpinion\ReadAloud\Provider\HttpProvider;
use IndianOpinion\ReadAloud\Provider\SilentProvider;
use IndianOpinion\ReadAloud\Provider\SpeechAudio;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Support\TempFiles;

/**
 * PRD 12.4, cases 36 to 40.
 */
final class ProviderTest extends TestCase
{
    /** @return array<string, mixed> */
    private function httpSettings(array $overrides = []): array
    {
        return $overrides + [
            'endpoint' => 'https://speech.example.test/v1/audio',
            'auth_header' => 'Authorization',
            'auth_template' => 'Bearer {key}',
            'api_key' => 'sk-test-key-123456789',
            'body_template' => '{"input": "{text}", "voice": "{voice}", "speed": "{speed}", "options": {"label": "voice {voice} at {speed}"}, "n": 1, "flags": [true, null]}',
            'response_mode' => HttpProvider::MODE_BINARY,
            'json_pointer' => '/audio',
            'max_chars' => '4000',
            'formats' => 'mp3, wav',
        ];
    }

    private function request(string $text = 'Hello there, this is a sentence.', string $format = 'mp3'): SpeechRequest
    {
        return new SpeechRequest($text, 'alloy', 'model-1', 'en-GB', $format, 1.25, SpeechRequest::newRequestId(), 30);
    }

    /** Test 31 (case 36). */
    public function testRegisteredProviderAppearsAndIsUsed(): void
    {
        $this->assertArrayHasKey('fake', $this->plugin->providers()->labels());
        $html = (new SettingsPage($this->plugin))->html();
        $this->assertStringContainsString('value="fake"', $html);
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertNotEmpty($this->provider->requests);
        $this->assertSame('fake', $this->record($post->ID)->pending['provider'] ?? null);
    }

    /** Test 32 (case 37): binary and JSON base64 modes. */
    public function testHttpBinaryAndJsonModes(): void
    {
        $audio = Mp3::silence(3);
        \WpStub::respond(['headers' => ['Content-Type' => 'audio/mpeg', 'X-Billed' => '31'], 'body' => $audio]);
        $provider = new HttpProvider($this->httpSettings(['billed_header' => 'X-Billed']));
        $result = $provider->synthesize($this->request());
        $this->assertSame($audio, file_get_contents($result->path));
        $this->assertSame(31, $result->billedChars);
        TempFiles::remove($result->path);

        \WpStub::respond(['headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['data' => ['audio' => base64_encode($audio), 'seconds' => 3.1]])]);
        $provider = new HttpProvider($this->httpSettings(['response_mode' => HttpProvider::MODE_JSON, 'json_pointer' => '/data/audio', 'duration_pointer' => '/data/seconds']));
        $result = $provider->synthesize($this->request());
        $this->assertSame($audio, file_get_contents($result->path));
        $this->assertSame(3.1, $result->durationSeconds);
        TempFiles::remove($result->path);

        \WpStub::respond(['headers' => ['Content-Type' => 'application/json'], 'body' => '{"data": {}}']);
        $this->expectException(InvalidAudioException::class);
        $provider->synthesize($this->request());
    }

    /** Test 33 (case 37): the silent provider. */
    public function testSilentProviderMakesNoRequest(): void
    {
        $provider = new SilentProvider();
        $text = str_repeat('word ', 60);
        $result = $provider->synthesize($this->request($text));
        $this->assertSame([], \WpStub::$http);
        $info = Mp3::scan($result->path);
        $this->assertEqualsWithDelta(mb_strlen($text) / 15 / 1.25, $info['duration'], 0.2);
        $this->assertFalse($provider->isBilled());
        TempFiles::remove($result->path);
        $wav = $provider->synthesize($this->request($text, 'wav'));
        $this->assertStringStartsWith('RIFF', (string) file_get_contents($wav->path, false, null, 0, 4));
        TempFiles::remove($wav->path);
    }

    /** Test 34 (case 38): JSON built at the value level. */
    public function testTemplateFillsTypedValues(): void
    {
        \WpStub::respond(['headers' => ['Content-Type' => 'audio/mpeg'], 'body' => Mp3::silence(2)]);
        $text = "He said \"stop\" \\ then left.\nNew line with {voice} inside.";
        $result = (new HttpProvider($this->httpSettings()))->synthesize($this->request($text));
        TempFiles::remove($result->path);
        $sent = \WpStub::$http[0];
        $body = json_decode((string) $sent['args']['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($text, $body['input']);
        $this->assertSame('alloy', $body['voice']);
        $this->assertSame(1.25, $body['speed']);
        $this->assertSame('voice alloy at 1.25', $body['options']['label']);
        $this->assertSame(1, $body['n']);
        $this->assertSame([true, null], $body['flags']);
        $this->assertSame('Bearer sk-test-key-123456789', $sent['args']['headers']['Authorization']);
        $this->assertTrue($sent['safe']);
    }

    /** Test 35 (case 39). */
    public function testHttpsAndPrivateAddresses(): void
    {
        $provider = new HttpProvider([]);
        $this->assertContains('The endpoint must use HTTPS.', $provider->validateSettings($this->httpSettings(['endpoint' => 'http://speech.example.test/'])));

        $page = new SettingsPage($this->plugin);
        $errors = $page->save(['provider' => 'http', 'provider_fields' => $this->httpSettings(['endpoint' => 'http://speech.example.test/']), 'enabled' => '1']);
        $this->assertContains('The endpoint must use HTTPS.', $errors);
        $this->assertSame('fake', $this->plugin->settings()->string('provider'));

        \WpStub::$privateHosts = ['tts.internal'];
        $local = new HttpProvider($this->httpSettings(['endpoint' => 'https://tts.internal/speak']));
        try {
            $local->synthesize($this->request());
            $this->fail('A private endpoint must be refused.');
        } catch (ProviderConfigException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }
        $this->assertSame([], \WpStub::$http);

        add_filter('read_aloud_allow_local_endpoint', fn (): bool => true);
        \WpStub::respond(['headers' => ['Content-Type' => 'audio/mpeg'], 'body' => Mp3::silence(2)]);
        $result = $local->synthesize($this->request());
        TempFiles::remove($result->path);
        $this->assertFalse(\WpStub::$http[0]['safe']);
    }

    /** Test 36 (case 40). */
    public function testCodePointCountingAndOverride(): void
    {
        $text = "e\u{0301}a\u{0308}"; // two letters, two combining marks
        $this->assertSame(4, (new SilentProvider())->countChars($text));
        $override = new class () extends AbstractProvider {
            public function id(): string
            {
                return 'bytes';
            }
            public function label(): string
            {
                return 'Bytes';
            }
            public function maxChars(): int
            {
                return 100;
            }
            public function formats(): array
            {
                return ['mp3'];
            }
            public function countChars(string $text): int
            {
                return strlen($text);
            }
            public function synthesize(SpeechRequest $request): SpeechAudio
            {
                throw new \LogicException('not called');
            }
        };
        $this->assertSame(6, $override->countChars($text));
    }
}
