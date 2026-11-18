<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Audio\Joiner;
use IndianOpinion\ReadAloud\Audio\Mp3;
use IndianOpinion\ReadAloud\Audio\Wav;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\RejectedTextException;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Tests\Support\FakeProvider;
use IndianOpinion\ReadAloud\Text\Chunker;

/**
 * PRD 12.5, cases 41 to 47.
 */
final class ChunkingTest extends TestCase
{
    /** Test 37 (case 41). */
    public function testSplitOrderAndLimits(): void
    {
        $chunker = new Chunker(60);
        $text = "Short first paragraph.\nSecond paragraph is here and it has two sentences. This is the second one of them.\nThird";
        $chunks = $chunker->chunk($text);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(60, mb_strlen($chunk));
        }
        $this->assertSame('Short first paragraph.', explode("\n", $chunks[0])[0]);
        $this->assertContains('This is the second one of them.', array_merge(...array_map(fn ($c) => explode("\n", $c), $chunks)));

        $commas = (new Chunker(30))->chunk('alpha beta gamma, delta epsilon zeta; eta theta iota kappa');
        $this->assertSame(['alpha beta gamma,', 'delta epsilon zeta;', 'eta theta iota kappa'], $commas);

        $words = [];
        for ($i = 0; $i < 900; $i++) {
            $words[] = 'word' . $i;
        }
        $sentence = implode(' ', $words);
        $this->assertGreaterThan(5000, mb_strlen($sentence));
        $pieces = (new Chunker(1000))->chunk($sentence);
        $this->assertGreaterThan(5, count($pieces));
        $this->assertSame($sentence, implode(' ', $pieces), 'no word is split and nothing is lost');
        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(1000, mb_strlen($piece));
        }

        $graphemes = (new Chunker(5))->chunk(str_repeat("ne\u{0301}", 4));
        foreach ($graphemes as $piece) {
            $this->assertSame(1, preg_match('/^\X+$/u', $piece));
            $this->assertStringStartsNotWith("\u{0301}", $piece);
        }
        $this->assertSame(str_repeat("ne\u{0301}", 4), implode('', $graphemes));
    }

    /** Five paragraphs that each fill most of a 1,000-character chunk. */
    private function fiveChunkContent(): string
    {
        $paragraphs = [];
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $seed) {
            $paragraphs[] = self::words(850, $seed);
        }

        return self::blocks($paragraphs);
    }

    /** Test 38 (cases 42, 43). */
    public function testEditingOneParagraphSendsOneChunk(): void
    {
        $post = $this->post(['post_title' => 'T', 'post_content' => $this->fiveChunkContent()]);
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertCount(5, $this->provider->requests);
        $this->plugin->review()->approve($post->ID, self::EDITOR);

        $this->edit($post, str_replace('charlie council', 'charlie committee', $post->post_content));
        $this->assertSame('queued', $this->trigger($post->ID, 'update'));
        $this->provider->requests = [];
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertCount(1, $this->provider->requests);
        $this->assertStringContainsString('charlie committee', $this->provider->requests[0]->text);
    }

    /** Test 39 (case 44). */
    public function testLongJobMemoryStaysBounded(): void
    {
        $this->settings(['cap_chars' => 1000000]);
        $paragraphs = [];
        for ($i = 0; $i < 68; $i++) {
            $paragraphs[] = self::words(850, 'p' . $i);
        }
        $post = $this->post(['post_title' => 'Long', 'post_content' => self::blocks($paragraphs)]);
        $this->assertGreaterThan(57000, mb_strlen($this->plugin->planner()->spokenText($post)));
        // Each chunk is a large file (about 1.5 MB), about 100 MB in all.
        $this->provider->script = array_fill(0, 80, static function (SpeechRequest $r) {
            return FakeProvider::bytes($r, Mp3::silence(370));
        });
        $before = memory_get_usage();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $peakGrowth = memory_get_peak_usage() - $before;
        $this->assertLessThan(32 * 1024 * 1024, $peakGrowth);
        $this->assertGreaterThan(50 * 1024 * 1024, $this->record($post->ID)->pending['bytes']);
    }

    /** Test 40 (case 45). */
    public function testKillSwitchStopsMidJobAndResumes(): void
    {
        $post = $this->post(['post_title' => 'T', 'post_content' => $this->fiveChunkContent()]);
        $this->provider->onCall = function (SpeechRequest $request): void {
            if (count($this->provider->requests) === 2) {
                update_option('read_aloud_enabled', '0');
            }
        };
        $this->trigger($post->ID, 'generate');
        $this->assertSame('stopped_kill', $this->run_($post->ID));
        $this->assertCount(2, $this->provider->requests);
        $this->assertSame(Status::QUEUED, $this->record($post->ID)->status);
        $this->assertSame(0, $this->today()['reserved_chars']);

        $this->provider->onCall = null;
        update_option('read_aloud_enabled', '1');
        $this->provider->requests = [];
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertCount(3, $this->provider->requests);
        $this->assertStringContainsString('charlie', $this->provider->requests[0]->text);
    }

    /** Test 41 (case 46). */
    public function testJoining(): void
    {
        $dir = $this->dir;
        $a = $dir . '/a.mp3';
        $b = $dir . '/b.mp3';
        $xing = "\xFF\xFB\x10\xC0" . str_repeat("\x00", 17) . 'Xing' . str_repeat("\x00", 79);
        file_put_contents($a, Mp3::id3Title('chunk a') . $xing . Mp3::silence(2) . 'TAG' . str_repeat("\x00", 125));
        file_put_contents($b, Mp3::id3Title('chunk b') . Mp3::silence(3));
        $framesA = Mp3::scan($a)['frames'];
        $framesB = Mp3::scan($b)['frames'];
        $out = $dir . '/joined.mp3';
        Mp3::join([$a, $b], $out, 'Joined title');
        $bytes = (string) file_get_contents($out);
        $this->assertSame(1, substr_count($bytes, 'ID3'));
        $this->assertStringNotContainsString('Xing', $bytes);
        $this->assertStringNotContainsString('TAG', $bytes);
        $this->assertSame($framesA + $framesB, Mp3::scan($out)['frames']);

        file_put_contents($dir . '/a.wav', Wav::silence(1));
        file_put_contents($dir . '/b.wav', Wav::silence(2));
        $joined = Wav::join([$dir . '/a.wav', $dir . '/b.wav'], $dir . '/j.wav');
        $info = Wav::parse($dir . '/j.wav');
        $this->assertSame(24000, $info['dataSize']);
        $this->assertSame(filesize($dir . '/j.wav') - 8, unpack('V', substr((string) file_get_contents($dir . '/j.wav'), 4, 4))[1]);
        $this->assertEqualsWithDelta(3.0, $joined['duration'], 0.01);

        $odd = 'RIFF' . pack('V', 36 + 4) . 'WAVE' . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, 16000, 16000, 1, 8) . 'data' . pack('V', 4) . "\x80\x80\x80\x80";
        file_put_contents($dir . '/c.wav', $odd);
        try {
            Wav::join([$dir . '/a.wav', $dir . '/c.wav'], $dir . '/bad.wav');
            $this->fail('Mismatched sample rates must fail.');
        } catch (InvalidAudioException $e) {
            $this->assertStringContainsString('sample rate', $e->getMessage());
        }

        $this->settings(['voice_map' => [['locale' => 'en-GB', 'voice' => 'v', 'model' => '', 'speed' => 1.0, 'format' => 'ogg']]]);
        $post = $this->post(['post_title' => 'T', 'post_content' => self::blocks([self::words(900, 'a'), self::words(900, 'b')])]);
        $this->trigger($post->ID, 'generate');
        $this->assertSame('failed', $this->run_($post->ID));
        $this->assertSame('format_not_joinable', $this->record($post->ID)->lastErrorCode);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame(0.0, (new Joiner())->measure($a, 'ogg'));
    }

    /** Test 42 (case 47). */
    public function testPermanentFailureOnChunkThreeLeavesNoFile(): void
    {
        $post = $this->post(['post_title' => 'T', 'post_content' => $this->fiveChunkContent()]);
        $this->provider->script = [null, null, new RejectedTextException('Policy')];
        $this->trigger($post->ID, 'generate');
        $this->assertSame('refused', $this->run_($post->ID));
        $this->assertNull($this->record($post->ID)->pending);
        $this->assertSame([], glob($this->uploads . '/read-aloud/*/*/*') ?: []);

        $other = $this->post(['post_title' => 'U', 'post_content' => $this->fiveChunkContent()]);
        $this->provider->script = [null, null, new \IndianOpinion\ReadAloud\Exception\ProviderConfigException('Bad voice')];
        $this->trigger($other->ID, 'generate');
        $this->assertSame('failed', $this->run_($other->ID));
        $this->assertSame(Status::FAILED, $this->record($other->ID)->status);
        $this->assertSame([], glob($this->uploads . '/read-aloud/*/*/*') ?: []);
    }
}
