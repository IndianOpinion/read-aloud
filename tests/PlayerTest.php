<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Front\Player;
use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Tests\Support\FakeStore;

/**
 * PRD 12.12, cases 85 to 94.
 */
final class PlayerTest extends TestCase
{
    /** Test 79 (case 85). */
    public function testRendersNothingWhenItShouldNot(): void
    {
        $none = $this->post(['post_title' => 'None']);
        $this->assertSame('', read_aloud_player($none->ID));

        $post = $this->approvedPost();
        $this->assertNotSame('', read_aloud_player($post->ID));

        $post->post_status = 'draft';
        $this->assertSame('', read_aloud_player($post->ID), 'ineligible');
        $post->post_status = 'publish';

        $record = $this->plugin->records()->get($post->ID);
        $record['current_hash'] = str_repeat('0', 64);
        $this->plugin->records()->save($post->ID, $record);
        $this->assertSame('', read_aloud_player($post->ID), 'stale and hidden');
        $record['current_hash'] = $record['content_hash'];
        $this->plugin->records()->save($post->ID, $record);

        $record['status'] = Status::MISSING;
        $this->plugin->records()->save($post->ID, $record);
        $this->assertSame('', read_aloud_player($post->ID), 'file missing');
        $record['status'] = Status::APPROVED;
        $this->plugin->records()->save($post->ID, $record);

        $this->settings(['check_file_exists' => true]);
        unlink($this->uploads . '/read-aloud/' . $record['file_key']);
        $this->assertSame('', read_aloud_player($post->ID), 'optional existence check');
        $this->settings(['check_file_exists' => false, 'show_player' => false]);
        $this->assertSame('', read_aloud_player($post->ID), 'show player off');
    }

    /** Test 80 (case 86). */
    public function testMarkup(): void
    {
        $post = $this->approvedPost();
        $record = $this->plugin->records()->get($post->ID);
        $record['file_url'] = 'https://example.test/a"b<c>.mp3';
        $record['mime'] = 'audio/mpeg" onload="x';
        $this->plugin->records()->save($post->ID, $record);
        $html = read_aloud_player($post->ID);
        $this->assertStringContainsString('<audio class="read-aloud__audio" controls preload="none">', $html);
        $this->assertStringNotContainsString('autoplay', $html);
        $this->assertStringContainsString('Listen to this article', $html);
        $this->assertStringContainsString('Read by a synthetic voice.', $html);
        $this->assertMatchesRegularExpression('~<time class="read-aloud__duration" datetime="PT\d+M\d+S">\d+:\d{2}</time>~', $html);
        $this->assertStringContainsString('src="https://example.test/a&quot;b&lt;c&gt;.mp3"', $html);
        $this->assertStringContainsString('type="audio/mpeg&quot; onload=&quot;x"', $html);
        $this->assertLessThan(1024, strlen($html));

        add_filter('read_aloud_player_labels', fn (array $l): array => ['label' => 'Hear it'] + $l);
        add_filter('read_aloud_player_html', fn (string $h): string => $h . '<!-- filtered -->');
        $this->assertStringContainsString('Hear it', read_aloud_player($post->ID));
        $this->assertStringEndsWith('<!-- filtered -->', read_aloud_player($post->ID));
    }

    /** Test 81 (case 87). */
    public function testSpeedScriptOnlyWithPlayer(): void
    {
        $this->settings(['speed_buttons' => true]);
        $none = $this->post(['post_title' => 'No audio']);
        read_aloud_player($none->ID);
        $this->assertNotContains(Player::SCRIPT, \WpStub::$enqueued);
        $post = $this->approvedPost();
        $html = read_aloud_player($post->ID);
        $this->assertContains(Player::SCRIPT, \WpStub::$enqueued);
        $this->assertStringContainsString('<audio', $html, 'the native player works without the script');
        $script = (string) file_get_contents(dirname(__DIR__) . '/assets/player-speed.js');
        $this->assertLessThan(3072, strlen($script));
        $this->assertDoesNotMatchRegularExpression('/fetch\(|XMLHttpRequest|sendBeacon|https?:\/\//', $script);
    }

    /** Test 82 (case 88). */
    public function testDownloadLinkOnlyWithSetting(): void
    {
        $post = $this->approvedPost();
        $this->assertStringNotContainsString('download', read_aloud_player($post->ID));
        $this->settings(['download_link' => true]);
        $this->assertStringContainsString('Download the audio', read_aloud_player($post->ID));
    }

    /** Test 83 (case 89). */
    public function testAutomaticInsertion(): void
    {
        $post = $this->approvedPost();
        \WpStub::$currentPost = $post->ID;
        $content = '<p>Body.</p>';
        $this->assertStringStartsWith('<figure class="read-aloud"', $this->plugin->onContent($content));
        $this->settings(['insert' => 'after']);
        $this->assertStringEndsWith('</figure>', (string) $this->plugin->onContent($content));
        $this->settings(['insert' => 'none']);
        $this->assertSame($content, $this->plugin->onContent($content));
        $this->settings(['insert' => 'before']);
        $post->post_content .= '<!-- wp:read-aloud/player /-->';
        $this->assertSame($content, $this->plugin->onContent($content));
        \WpStub::$conditionals['is_feed'] = true;
        $post->post_content = str_replace('<!-- wp:read-aloud/player /-->', '', $post->post_content);
        $this->assertSame($content, $this->plugin->onContent($content));
    }

    /** Test 84 (case 90). */
    public function testTemplateFunctionMatchesBlock(): void
    {
        $post = $this->approvedPost();
        $block = $this->plugin->player()->renderBlock([], '', new \WP_Block(['postId' => $post->ID]));
        $this->assertSame(read_aloud_player($post->ID), $block);
        $this->assertSame(read_aloud_player($post), $block);
    }

    /** Test 85 (case 91). */
    public function testRenderingMakesNoCallsAndNoFileChecks(): void
    {
        $store = new FakeStore();
        $plugin = new Plugin($this->clock, $this->ledger, $store);
        Plugin::setInstance($plugin);
        $this->plugin = $plugin;
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->approvedPost(['post_title' => 'Story ' . $i])->ID;
        }
        $store->calls = [];
        \WpStub::$http = [];
        \WpStub::$cron = [];
        $requests = count($this->provider->requests);
        $metaBefore = \WpStub::$meta;
        for ($i = 0; $i < 100; $i++) {
            $this->assertNotSame('', read_aloud_player($ids[$i % 3]));
        }
        $this->assertSame([], $store->calls, 'no file system or storage call');
        $this->assertSame([], \WpStub::$http);
        $this->assertSame([], \WpStub::$cron, 'no job queued');
        $this->assertSame($requests, count($this->provider->requests));
        $this->assertSame($metaBefore, \WpStub::$meta, 'nothing written');
    }

    /** Test 86 (case 92). */
    public function testFeedEnclosure(): void
    {
        $post = $this->approvedPost();
        \WpStub::$currentPost = $post->ID;
        ob_start();
        $this->plugin->onFeedItem();
        $this->assertSame('', ob_get_clean());
        $this->settings(['feed_enclosure' => true]);
        ob_start();
        $this->plugin->onFeedItem();
        $out = (string) ob_get_clean();
        $record = $this->record($post->ID);
        $this->assertStringContainsString('url="' . $record->fileUrl . '"', $out);
        $this->assertStringContainsString('length="' . $record->bytes . '"', $out);
        $this->assertStringContainsString('type="audio/mpeg"', $out);

        $this->settings(['voice_map' => [['locale' => 'en-GB', 'voice' => 'changed', 'model' => '', 'speed' => 1.0, 'format' => 'mp3']]]);
        $this->plugin->maintenance()->markOutdated();
        ob_start();
        $this->plugin->onFeedItem();
        $this->assertSame('', ob_get_clean(), 'ruling 9: outdated_settings is skipped');
    }

    /** Test 87 (cases 93, 94). */
    public function testApprovalPurgesCachesAndReadmeSaysCacheForAYear(): void
    {
        $changed = [];
        add_action('read_aloud_player_changed', function (int $postId) use (&$changed): void {
            $changed[] = $postId;
        });
        $post = $this->approvedPost();
        $this->assertContains($post->ID, \WpStub::$cleaned);
        $this->assertSame([$post->ID], $changed);
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $this->assertMatchesRegularExpression('/cached for (a|one) year/i', $readme);
    }
}
