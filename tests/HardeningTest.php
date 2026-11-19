<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Audio\Mp3;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\ProviderConfigException;
use IndianOpinion\ReadAloud\Exception\StorageException;
use IndianOpinion\ReadAloud\Provider\EndpointGuard;
use IndianOpinion\ReadAloud\Provider\HttpProvider;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Storage\ChunkCache;
use IndianOpinion\ReadAloud\Storage\UploadsStore;
use IndianOpinion\ReadAloud\Text\Hashes;

/**
 * Review fixes: unpublished text, lock loss, guessable file names, store
 * keys, deny files, hostile mp3 bytes and the HTTP adapter (PRD 4.6, 4.5,
 * section 8).
 */
final class HardeningTest extends TestCase
{
    private function threeChunkContent(): string
    {
        return self::blocks([self::words(850, 'alpha'), self::words(850, 'bravo'), self::words(850, 'charlie')]);
    }

    /** Case 8, 50: a post unpublished mid-job sends no further chunk. */
    public function testPostUnpublishedMidJobStopsBeforeNextChunk(): void
    {
        $post = $this->post(['post_title' => 'T', 'post_content' => $this->threeChunkContent()]);
        $this->provider->onCall = function () use ($post): void {
            if (count($this->provider->requests) === 1) {
                $post->post_status = 'draft';
            }
        };
        $this->trigger($post->ID, 'generate');
        $this->assertSame('cancelled', $this->run_($post->ID));
        $this->assertCount(1, $this->provider->requests, 'no draft text after the first chunk');
        $this->assertSame(0, $this->today()['reserved_chars']);
        $this->assertNull($this->record($post->ID)->pending);
    }

    /** Case 8, 50: opting the post out mid-job stops it too. */
    public function testPostOptedOutMidJobStops(): void
    {
        $post = $this->post(['post_title' => 'T', 'post_content' => $this->threeChunkContent()]);
        $this->provider->onCall = function () use ($post): void {
            $this->plugin->records()->setMode($post->ID, 'off');
        };
        $this->trigger($post->ID, 'generate');
        $this->assertSame('cancelled', $this->run_($post->ID));
        $this->assertCount(1, $this->provider->requests);
    }

    /** Case 49: a run that lost its lock stops and leaves the record alone. */
    public function testLostLockStopsTheRun(): void
    {
        $post = $this->post(['post_title' => 'T', 'post_content' => $this->threeChunkContent()]);
        $this->provider->onCall = function () use ($post): void {
            if (count($this->provider->requests) === 1) {
                $this->clock->advance(901);
                $this->ledger->lock('post-' . $post->ID, 'other-runner', $this->clock->now(), 900);
            }
        };
        $this->trigger($post->ID, 'generate');
        $this->assertSame('locked', $this->run_($post->ID));
        $this->assertCount(1, $this->provider->requests);
        $this->assertSame(0, $this->today()['reserved_chars']);
    }

    /** Case 49: a long run keeps its lock alive between chunks. */
    public function testLongRunRenewsItsLock(): void
    {
        $post = $this->post(['post_title' => 'T', 'post_content' => $this->threeChunkContent()]);
        $this->provider->onCall = function () use ($post): void {
            // Each chunk takes ten minutes: without renewal the lock expires.
            $this->clock->advance(600);
            $this->assertSame('busy', $this->ledger->lock('post-' . $post->ID, 'intruder', $this->clock->now(), 900));
        };
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertCount(3, $this->provider->requests);
    }

    /** Case 66: a pending file's URL cannot be worked out from public text and settings. */
    public function testPendingFileNameIsKeyedWithTheSiteSecret(): void
    {
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $pending = $this->record($post->ID)->pending;
        $this->assertIsArray($pending);
        $plan = $this->plugin->planner()->plan($post);
        $guess = Hashes::fingerprint($plan->contentHash, $plan->renderHash);
        $this->assertNotSame($guess, $pending['fingerprint']);
        $this->assertSame(Hashes::fingerprint($plan->contentHash, $plan->renderHash, $this->plugin->settings()->secret()), $pending['fingerprint']);
        $this->assertSame($this->plugin->settings()->secret(), $this->plugin->settings()->secret(), 'stable');
        $this->assertSame(64, strlen($this->plugin->settings()->secret()));
        $this->assertFileExists($this->uploads . '/read-aloud/index.html', 'no directory listing');
        $this->assertFileExists(dirname($this->uploads . '/read-aloud/' . $pending['file_key']) . '/index.html');
    }

    /** Section 8: only the two key shapes, no traversal, no trailing newline. */
    public function testStoreKeysRejectTraversal(): void
    {
        $this->assertTrue(UploadsStore::validKey('2026/11/12-0123456789ab.mp3'));
        $this->assertTrue(UploadsStore::validKey('test/0123456789abcdef.wav'));
        foreach (
            [
                "2026/11/12-0123456789ab.mp3\n",
                '../2026/11/12-0123456789ab.mp3',
                '2026/11/../12-0123456789ab.mp3',
                '/2026/11/12-0123456789ab.mp3',
                '2026/11/12-0123456789ab.php',
                'test/../../wp-config.mp3',
                '2026/11/12-0123456789ab.mp3/x',
            ] as $key
        ) {
            $this->assertFalse(UploadsStore::validKey($key), var_export($key, true));
        }
        $store = new UploadsStore($this->uploads . '/read-aloud', 'https://example.org/read-aloud');
        $tmp = $this->dir . '/a.mp3';
        file_put_contents($tmp, Mp3::silence(1));
        $this->expectException(StorageException::class);
        $store->put($tmp, "2026/11/12-0123456789ab.mp3\n", 'audio/mpeg');
    }

    /** Ruling 7: a deny file that was emptied or edited is rewritten. */
    public function testTamperedDenyFileIsRewritten(): void
    {
        $base = $this->dir . '/site/read-aloud';
        mkdir($base . '/.chunks', 0775, true);
        file_put_contents($base . '/.chunks/.htaccess', '');
        $cache = ChunkCache::locate(true, null, null, $base);
        $this->assertTrue($cache->enabled());
        $this->assertSame(ChunkCache::DENY_FILES['.htaccess'], file_get_contents($base . '/.chunks/.htaccess'));
    }

    /** Case 46: the frame walker survives hostile bytes. */
    public function testMp3ScannerOnHostileBytes(): void
    {
        $cases = [
            'html' => '<html>' . str_repeat('error ', 5000) . '</html>',
            'syncs' => str_repeat("\xFF\xFB\xFF\xFF", 30000),
            'huge_id3' => 'ID3' . "\x04\x00\x00" . "\x7F\x7F\x7F\x7F" . str_repeat("\x00", 100),
            'truncated' => substr(Mp3::silence(1), 0, 50),
            'free_format' => str_repeat("\xFF\xFB\x00\x00" . str_repeat("\x00", 200), 50),
            'random' => random_bytes(300000),
        ];
        foreach ($cases as $name => $bytes) {
            $path = $this->dir . '/' . $name . '.mp3';
            file_put_contents($path, $bytes);
            $started = microtime(true);
            try {
                $info = Mp3::scan($path);
                $this->assertGreaterThanOrEqual(0, $info['frames'], $name);
                $this->assertGreaterThanOrEqual(0.0, $info['duration'], $name);
            } catch (InvalidAudioException $e) {
                $this->assertStringContainsString('No MPEG audio frames', $e->getMessage(), $name);
            }
            $this->assertLessThan(10.0, microtime(true) - $started, $name . ' scans in bounded time');
        }

        // Junk between valid frames is skipped; mixed sample rates measure per frame.
        $mixed = $this->dir . '/mixed.mp3';
        $mpeg2 = "\xFF\xF3\x10\xC0" . str_repeat("\x00", 44);
        file_put_contents($mixed, Mp3::silence(1) . 'junk' . str_repeat($mpeg2, 10));
        $info = Mp3::scan($mixed);
        $one = Mp3::scan($this->writeSilence(1));
        $this->assertSame($one['frames'] + 10, $info['frames']);
        $this->assertEqualsWithDelta($one['duration'] + 10 * 576 / 22050, $info['duration'], 0.001);
    }

    private function writeSilence(float $seconds): string
    {
        $path = $this->dir . '/silence-' . $seconds . '.mp3';
        file_put_contents($path, Mp3::silence($seconds));

        return $path;
    }

    /** Case 39, section 8: header values carry no line breaks; responses are size-limited. */
    public function testHttpHeaderInjectionAndSizeLimit(): void
    {
        $provider = new HttpProvider([
            'endpoint' => 'https://speech.example.com/v1/speak',
            'api_key' => "abc\r\nX-Evil: 1",
            'formats' => 'mp3',
        ]);
        \WpStub::respond(['headers' => ['Content-Type' => 'audio/mpeg'], 'body' => Mp3::silence(2)]);
        $audio = $provider->synthesize(new SpeechRequest(self::words(100), 'v', '', 'en-GB', 'mp3', 1.0, SpeechRequest::newRequestId(), 30));
        $args = \WpStub::$http[0]['args'];
        $this->assertSame('Bearer abcX-Evil: 1', $args['headers']['Authorization']);
        $this->assertSame(HttpProvider::MAX_RESPONSE_BYTES, $args['limit_response_size']);
        $this->assertSame(0, $args['redirection'], 'no redirect can lead to a private address');
        $this->assertTrue(\WpStub::$http[0]['safe']);
        unlink($audio->path);
    }

    /** Case 81 (review ruling): invalid audio came back with a 200 and counts as spent. */
    public function testInvalidAudioCountsAsSpent(): void
    {
        $post = $this->post();
        $this->provider->script = [fn (SpeechRequest $r) => \IndianOpinion\ReadAloud\Tests\Support\FakeProvider::bytes($r, str_repeat('x', 100000))];
        $this->trigger($post->ID, 'generate');
        $this->assertSame('retrying', $this->run_($post->ID));
        $this->assertGreaterThan(0, $this->today()['chars']);
        $this->assertSame(0, $this->today()['reserved_chars']);
        $this->assertSame(Status::RETRYING, $this->record($post->ID)->status);
    }
    /** Case 39, section 8: every resolved address must be public, IPv6 and mapped forms included. */
    public function testEndpointGuardRefusesNonPublicAddresses(): void
    {
        foreach (['169.254.169.254', '100.64.1.1', '127.0.0.1', '10.1.2.3', '172.20.0.1', '192.168.1.1', '0.0.0.0', '198.18.0.1', '224.0.0.1', '::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::1', 'ff02::1', 'not-an-ip'] as $address) {
            $this->assertFalse(EndpointGuard::isPublic($address), $address . ' must be refused');
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111', '::ffff:8.8.8.8'] as $address) {
            $this->assertTrue(EndpointGuard::isPublic($address), $address . ' is public');
        }
    }

    /** Case 39: a host with one private record among public ones is refused before any request. */
    public function testHttpAdapterRefusesHostWithAnyPrivateAddress(): void
    {
        \WpStub::$dns['speech.example.com'] = ['93.184.216.34', '169.254.169.254'];
        $provider = new HttpProvider(['endpoint' => 'https://speech.example.com/v1/speak', 'formats' => 'mp3']);
        try {
            $provider->synthesize(new SpeechRequest(self::words(100), 'v', '', 'en-GB', 'mp3', 1.0, SpeechRequest::newRequestId(), 30));
            $this->fail('A host that resolves to a link-local address must be refused.');
        } catch (ProviderConfigException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }
        $this->assertSame([], \WpStub::$http, 'no request was made');

        \WpStub::$dns['v6.example.com'] = ['2606:4700:4700::1111', 'fd00::5'];
        $provider = new HttpProvider(['endpoint' => 'https://v6.example.com/speak', 'formats' => 'mp3']);
        $this->expectException(ProviderConfigException::class);
        $provider->synthesize(new SpeechRequest(self::words(100), 'v', '', 'en-GB', 'mp3', 1.0, SpeechRequest::newRequestId(), 30));
    }

    /** Case 39: the connection is pinned to the checked address only while the request runs. */
    public function testHttpAdapterPinsTheCheckedAddress(): void
    {
        \WpStub::$dns['speech.example.com'] = ['93.184.216.34'];
        $seen = null;
        \WpStub::respond(function () use (&$seen): array {
            $seen = has_action('http_api_curl');
            return ['headers' => ['Content-Type' => 'audio/mpeg'], 'body' => Mp3::silence(2)];
        });
        $provider = new HttpProvider(['endpoint' => 'https://speech.example.com/v1/speak', 'formats' => 'mp3']);
        $audio = $provider->synthesize(new SpeechRequest(self::words(100), 'v', '', 'en-GB', 'mp3', 1.0, SpeechRequest::newRequestId(), 30));
        unlink($audio->path);
        $this->assertTrue($seen, 'the pin is in place during the request');
        $this->assertFalse(has_action('http_api_curl'), 'and removed after it');
    }

    /** Case 37, 73: the silent adapter never asks the spend meter (it bills nothing). */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testUnbilledProviderSkipsTheMeter(): void
    {
        define('SPEND_METER_API', 1);
        $asks = 0;
        add_filter('spend_meter_pre_spend', function () use (&$asks) {
            $asks++;
            return ['allowed' => false, 'reason' => 'unpriced_model', 'message' => '', 'reservation_id' => null, 'estimate' => 0, 'remaining' => null, 'api' => 1];
        }, 10, 2);
        $this->provider->billed = false;
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertSame(0, $asks);
    }

    /** Ruling 7: a post's chunk folder gets an empty index so listings show nothing. */
    public function testChunkFolderHasAnIndex(): void
    {
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $dir = (string) $this->plugin->cache()->directory() . '/' . $post->ID;
        $this->assertFileExists($dir . '/index.html');
        $this->assertGreaterThan(0, $this->plugin->cache()->deletePost($post->ID));
        $this->assertDirectoryDoesNotExist($dir);
    }
}
