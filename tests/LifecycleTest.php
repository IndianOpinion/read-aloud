<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Cli\Command;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Storage\AudioStore;
use IndianOpinion\ReadAloud\Tests\Support\FakeStore;

/**
 * PRD 12.13, cases 95 to 101 and 5.2.
 */
final class LifecycleTest extends TestCase
{
    private function replace(\WP_Post $post): string
    {
        $old = $this->record($post->ID)->fileKey;
        $this->edit($post, str_replace('council', 'board ' . uniqid(), $post->post_content));
        $this->trigger($post->ID, 'update');
        $this->assertSame('done', $this->run_($post->ID));
        $this->plugin->review()->approve($post->ID, self::EDITOR);

        return $old;
    }

    /** Test 88 (case 95). */
    public function testOldFileDeletedAfterGrace(): void
    {
        $post = $this->approvedPost();
        $old = $this->replace($post);
        $path = $this->uploads . '/read-aloud/' . $old;
        $this->assertFileExists($path);
        $this->clock->advance(47 * 3600);
        $this->plugin->maintenance()->daily();
        $this->assertFileExists($path, 'cached HTML may still point to it');
        $this->clock->advance(2 * 3600);
        $this->plugin->maintenance()->daily();
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($this->uploads . '/read-aloud/' . $this->record($post->ID)->fileKey);
    }

    /** Test 89 (case 96). */
    public function testTrashHidesRestoreShows(): void
    {
        $post = $this->approvedPost();
        $calls = count($this->provider->requests);
        $post->post_status = 'trash';
        $this->plugin->onStatusHook($post->ID);
        $this->plugin->flushDeferred();
        $this->assertSame('', read_aloud_player($post->ID));
        $this->assertFileExists($this->uploads . '/read-aloud/' . $this->record($post->ID)->fileKey);
        $post->post_status = 'publish';
        $this->plugin->onStatusHook($post->ID);
        $this->assertSame([$post->ID => 'unchanged'], $this->plugin->flushDeferred());
        $this->assertNotSame('', read_aloud_player($post->ID));
        $this->assertSame($calls, count($this->provider->requests));
    }

    /** Test 90 (case 97). */
    public function testPermanentDeletion(): void
    {
        $post = $this->approvedPost();
        $key = $this->record($post->ID)->fileKey;
        $chunkDir = $this->plugin->cache()->directory() . '/' . $post->ID;
        $this->assertDirectoryExists($chunkDir);
        $this->plugin->onDelete($post->ID);
        $this->assertFileDoesNotExist($this->uploads . '/read-aloud/' . $key);
        $this->assertDirectoryDoesNotExist($chunkDir);
        $this->assertNull(read_aloud_get($post->ID));
    }

    /** Test 91 (case 98). */
    public function testRemoveAudio(): void
    {
        $post = $this->approvedPost();
        $key = $this->record($post->ID)->fileKey;
        $this->plugin->review()->remove($post->ID, self::EDITOR);
        $this->assertFileDoesNotExist($this->uploads . '/read-aloud/' . $key);
        $this->assertSame(Records::MODE_OFF, $this->plugin->records()->mode($post->ID));
        $this->assertSame('ineligible', $this->trigger($post->ID, 'update'));
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
    }

    /** Test 92 (case 99). */
    public function testMissingFileAndVerifyRequeue(): void
    {
        $post = $this->approvedPost();
        $calls = count($this->provider->requests);
        unlink($this->uploads . '/read-aloud/' . $this->record($post->ID)->fileKey);
        $this->plugin->maintenance()->daily();
        $this->assertSame(Status::MISSING, $this->record($post->ID)->status);
        $this->assertSame('', read_aloud_player($post->ID));
        $this->assertSame('unchanged', $this->trigger($post->ID, 'update'), 'nothing regenerates automatically');
        $this->assertSame($calls, count($this->provider->requests));

        (new Command($this->plugin))->verify([], ['requeue' => true]);
        $this->assertSame(Status::QUEUED, $this->record($post->ID)->status);
        $this->assertCount(1, \WpStub::cronFor('read_aloud_job'));
    }

    /** Test 93 (case 100). */
    public function testChunkPruningAndCacheOff(): void
    {
        $post = $this->approvedPost();
        $dir = $this->plugin->cache()->directory() . '/' . $post->ID;
        $live = array_diff(glob($dir . '/*') ?: [], [$dir . '/index.html']);
        $this->assertNotEmpty($live);
        $this->replace($post);
        $all = array_diff(glob($dir . '/*') ?: [], [$dir . '/index.html']);
        $this->assertGreaterThan(count($live), count($all));
        $this->clock->advance(8 * 86400);
        $this->plugin->maintenance()->daily();
        $kept = array_diff(glob($dir . '/*') ?: [], [$dir . '/index.html']);
        $this->assertSame(count($this->record($post->ID)->toArray()['chunks']), count($kept));

        $this->settings(['chunk_cache' => false]);
        $plugin = new Plugin($this->clock, $this->ledger);
        Plugin::setInstance($plugin);
        $this->plugin = $plugin;
        $this->assertFalse($plugin->cache()->enabled());
        $other = $this->post(['post_title' => 'No cache']);
        $this->trigger($other->ID, 'generate');
        $this->assertSame('done', $this->run_($other->ID));
        $this->assertDirectoryDoesNotExist(dirname($dir) . '/' . $other->ID);
    }

    /** Test 94 (case 101). */
    public function testPruneOrphans(): void
    {
        $post = $this->approvedPost();
        $orphan = $this->uploads . '/read-aloud/2026/01/999-0123456789ab.mp3';
        mkdir(dirname($orphan), 0775, true);
        file_put_contents($orphan, 'x');
        $command = new Command($this->plugin);
        $command->prune([], ['dry-run' => true]);
        $this->assertContains('2026/01/999-0123456789ab.mp3', \WP_CLI::$lines);
        $this->assertFileExists($orphan);
        $command->prune([], []);
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileExists($this->uploads . '/read-aloud/' . $this->record($post->ID)->fileKey);
    }

    /** Test 95 (section 5.2). */
    public function testCustomStoreFromFilter(): void
    {
        $store = new FakeStore();
        add_filter('read_aloud_store', fn (?AudioStore $s): AudioStore => $store);
        $plugin = new Plugin($this->clock, $this->ledger);
        Plugin::setInstance($plugin);
        $this->plugin = $plugin;
        $post = $this->approvedPost();
        $this->assertCount(1, array_filter($store->calls, fn (string $c): bool => str_starts_with($c, 'put:')));
        $this->assertStringContainsString('https://cdn.example.test/audio/', read_aloud_player($post->ID));
    }
}
