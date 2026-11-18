<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Admin\SettingsPage;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Exception\AccountException;
use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\RateLimitedException;
use IndianOpinion\ReadAloud\Exception\RejectedTextException;
use IndianOpinion\ReadAloud\Exception\TransientProviderException;
use IndianOpinion\ReadAloud\Storage\ChunkCache;

/**
 * PRD 12.11, cases 78 to 84.
 */
final class ErrorsTest extends TestCase
{
    /** Test 72 (case 78). */
    public function testRetriesAndRetryAfter(): void
    {
        $post = $this->post();
        $this->provider->script = [new TransientProviderException('HTTP 503', null, 503)];
        $this->trigger($post->ID, 'generate');
        $this->assertSame('retrying', $this->run_($post->ID));
        $record = $this->record($post->ID);
        $this->assertSame(Status::RETRYING, $record->status);
        $delay = $record->nextAttemptAt - $this->clock->now();
        $this->assertGreaterThanOrEqual(300, $delay);
        $this->assertLessThanOrEqual(330, $delay);
        $this->clock->advance($delay);
        $this->assertSame(['done' => 1], $this->plugin->runner()->runDue());
        $this->assertSame(Status::PENDING_REVIEW, $this->record($post->ID)->status);

        $limited = $this->post(['post_title' => 'Limited']);
        $this->provider->script = [new RateLimitedException('HTTP 429', 120, 429)];
        $this->trigger($limited->ID, 'generate');
        $this->run_($limited->ID);
        $this->assertSame(120, $this->record($limited->ID)->nextAttemptAt - $this->clock->now());

        $slow = $this->post(['post_title' => 'Slow']);
        $this->provider->script = [new RateLimitedException('HTTP 429', 172800, 429)];
        $this->trigger($slow->ID, 'generate');
        $this->run_($slow->ID);
        $this->assertSame(21600, $this->record($slow->ID)->nextAttemptAt - $this->clock->now());
    }

    /** Test 73 (case 79). */
    public function testAuthFailureHoldsTheSite(): void
    {
        $held = [];
        add_action('read_aloud_account_hold', function (string $provider, string $code) use (&$held): void {
            $held[] = $provider;
        }, 10, 2);
        $a = $this->post(['post_title' => 'A']);
        $b = $this->post(['post_title' => 'B']);
        $this->trigger($a->ID, 'generate');
        $this->trigger($b->ID, 'generate');
        $this->provider->script = [new AccountException('HTTP 401: invalid key sk-abcdefghijklmnop', 401)];
        $this->assertSame(['held' => 1], $this->plugin->runner()->runDue());
        $this->assertSame(['fake'], $held);
        $this->assertSame(Status::HELD, $this->record($a->ID)->status);
        $this->assertCount(1, $this->provider->requests);
        $this->assertSame('held', $this->run_($b->ID));
        $this->assertCount(1, $this->provider->requests, 'no other post is called');
        $this->assertStringNotContainsString('sk-abcdefghijklmnop', $this->record($a->ID)->lastErrorMessage);
        \WpStub::$currentUser = self::ADMIN;
        $this->assertStringContainsString('on hold', (new \IndianOpinion\ReadAloud\Admin\Notices($this->plugin))->html());

        (new SettingsPage($this->plugin))->save(['provider' => 'fake', 'enabled' => '1', 'categories' => [self::NEWS], 'show_player' => '1', 'chunk_cache' => '1']);
        $this->assertFalse($this->plugin->guard()->isHeld());
        $this->assertSame(Status::QUEUED, $this->record($a->ID)->status);
        $this->assertSame(['done' => 2], $this->plugin->runner()->runDue());
    }

    /** Test 74 (case 80). */
    public function testRefusalIsStoredRedactedAndNotRetried(): void
    {
        $post = $this->post();
        $message = 'Content policy: text refused. Authorization: Bearer abcdefghijklmnopqrstuvwxyz0123456789 ' . str_repeat('detail ', 60);
        $this->provider->script = [new RejectedTextException($message)];
        $this->trigger($post->ID, 'generate');
        $this->assertSame('refused', $this->run_($post->ID));
        $record = $this->record($post->ID);
        $this->assertSame(Status::REFUSED, $record->status);
        $this->assertLessThanOrEqual(200, mb_strlen($record->lastErrorMessage));
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789', $record->lastErrorMessage);
        $this->assertStringStartsWith('Content policy', $record->lastErrorMessage);
        $this->assertSame('refused', $this->trigger($post->ID, 'update'));
    }

    /** Test 75 (case 81). */
    public function testInvalidAudioTwiceFails(): void
    {
        $post = $this->post();
        $this->provider->script = [new InvalidAudioException('html'), new InvalidAudioException('html')];
        $this->trigger($post->ID, 'generate');
        $this->assertSame('retrying', $this->run_($post->ID));
        $this->clock->advance(400);
        $this->assertSame('failed', $this->run_($post->ID));
        $this->assertSame(Status::FAILED, $this->record($post->ID)->status);
        $this->assertSame('invalid_audio', $this->record($post->ID)->lastErrorCode);
    }

    /** Test 76 (case 82). */
    public function testStorageErrorKeepsChunksAndReleases(): void
    {
        $post = $this->post();
        mkdir($this->uploads . '/read-aloud', 0775, true);
        file_put_contents($this->uploads . '/read-aloud/2026', 'not a directory');
        $this->trigger($post->ID, 'generate');
        $this->assertSame('retrying', $this->run_($post->ID));
        $record = $this->record($post->ID);
        $this->assertSame('storage_error', $record->lastErrorCode);
        $this->assertSame(0, $this->today()['reserved_chars']);
        $this->assertSame(0, $this->today()['generations']);
        $calls = count($this->provider->requests);

        unlink($this->uploads . '/read-aloud/2026');
        $this->clock->advance(400);
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertSame($calls, count($this->provider->requests), 'the chunk cache was kept');
    }

    /** Test 77 (case 83). */
    public function testKilledJobResumesAfterLockExpiry(): void
    {
        $paragraphs = [];
        foreach (['a', 'b', 'c', 'd'] as $seed) {
            $paragraphs[] = self::words(850, $seed);
        }
        $post = $this->post(['post_title' => 'T', 'post_content' => self::blocks($paragraphs)]);
        $this->trigger($post->ID, 'generate');
        $this->provider->onCall = function (): void {
            if (count($this->provider->requests) === 3) {
                throw new \RuntimeException('Simulated fatal error');
            }
        };
        try {
            $this->run_($post->ID);
        } catch (\RuntimeException $e) {
            // A real fatal error never unwinds; leave the lock as a dead run would.
        }
        $this->ledger->lock('post-' . $post->ID, 'dead-run', $this->clock->now(), 900);
        $this->provider->onCall = null;
        $this->provider->requests = [];
        $this->assertSame('locked', $this->run_($post->ID));
        $this->clock->advance(901);
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertCount(2, $this->provider->requests, 'only the missing chunks are sent');
    }

    /** Test 78 (case 84). */
    public function testFourAttemptsThenFailed(): void
    {
        $post = $this->post();
        $this->provider->script = array_fill(0, 4, new TransientProviderException('HTTP 500', null, 500));
        $this->trigger($post->ID, 'generate');
        $outcomes = [];
        for ($i = 0; $i < 4; $i++) {
            $outcomes[] = $this->run_($post->ID);
            $this->clock->advance(8000);
        }
        $this->assertSame(['retrying', 'retrying', 'retrying', 'failed'], $outcomes);
        $this->assertCount(4, $this->provider->requests);
        $this->assertSame(Status::FAILED, $this->record($post->ID)->status);
        $this->assertSame('failed', $this->trigger($post->ID, 'update'));
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
    }

    /** Ruling 7: deny files under uploads, and fail closed. */
    public function testChunkCacheLocation(): void
    {
        $outside = $this->plugin->cache();
        $this->assertTrue($outside->isOutsideWebRoot());
        $this->assertStringStartsWith(dirname(rtrim(ABSPATH, '/')), (string) $outside->directory());

        $inside = ChunkCache::locate(true, null, null, $this->uploads . '/read-aloud');
        $this->assertFalse($inside->isOutsideWebRoot());
        $this->assertFileExists($this->uploads . '/read-aloud/.chunks/.htaccess');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($this->uploads . '/read-aloud/.chunks/.htaccess'));
        $this->assertFileExists($this->uploads . '/read-aloud/.chunks/index.html');

        $locked = $this->dir . '/locked';
        mkdir($locked . '/read-aloud/.chunks', 0775, true);
        mkdir($locked . '/read-aloud/.chunks/.htaccess');
        $closed = ChunkCache::locate(true, null, null, $locked . '/read-aloud');
        $this->assertFalse($closed->enabled(), 'no deny file, no cache');
    }
}
