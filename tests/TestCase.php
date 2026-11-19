<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Data\AudioRecord;
use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Tests\Support\FakeProvider;
use IndianOpinion\ReadAloud\Tests\Support\FixedClock;
use IndianOpinion\ReadAloud\Tests\Support\MemoryLedger;
use IndianOpinion\ReadAloud\Uninstaller;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const NEWS = 5;
    public const SPORT = 6;
    public const LOCAL = 7;
    public const ADMIN = 1;
    public const EDITOR = 2;
    public const READER = 3;

    protected FixedClock $clock;
    protected MemoryLedger $ledger;
    protected FakeProvider $provider;
    protected Plugin $plugin;
    protected string $dir;
    protected string $uploads;
    private static int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = READ_ALOUD_TEST_ROOT . '/case-' . (++self::$counter);
        $this->uploads = $this->dir . '/uploads';
        mkdir($this->uploads, 0775, true);
        Uninstaller::removeTree(dirname(rtrim(ABSPATH, '/')) . '/read-aloud-chunks');
        \WpStub::reset($this->uploads);
        \WP_CLI::reset();
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];

        \WpStub::term(self::NEWS, 'News');
        \WpStub::term(self::SPORT, 'Sport');
        \WpStub::term(self::LOCAL, 'Local', self::NEWS);
        \WpStub::can(self::ADMIN, ['manage_options' => true, 'edit_post' => true]);
        \WpStub::can(self::EDITOR, ['edit_post' => true]);
        \WpStub::can(self::READER, []);

        $this->clock = new FixedClock();
        $this->ledger = new MemoryLedger();
        $this->provider = new FakeProvider(1000);
        add_filter('read_aloud_providers', function (array $providers): array {
            return $providers + ['fake' => fn (array $settings) => $this->provider];
        });
        $this->plugin = $this->makePlugin();
        Plugin::setInstance($this->plugin);
        $this->settings([
            'provider' => 'fake',
            'voice_map' => [['locale' => 'en-GB', 'voice' => 'narrator', 'model' => 'm1', 'speed' => 1.0, 'format' => 'mp3']],
            'categories' => [self::NEWS],
        ]);
    }

    protected function makePlugin(): Plugin
    {
        return new Plugin($this->clock, $this->ledger);
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
        Uninstaller::removeTree($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        $settings = $this->plugin->settings();
        $settings->replace($values + $settings->all());
    }

    /** A paragraph of plain words, about $chars long. */
    protected static function words(int $chars, string $seed = 'river'): string
    {
        $base = 'The ' . $seed . ' council met on Tuesday and agreed a new plan for the town, with more trees and safer roads';
        $out = '';
        $i = 0;
        while (mb_strlen($out) < $chars) {
            $out .= ($out === '' ? '' : ' ') . $base . ' number ' . (++$i) . '.';
        }

        return $out;
    }

    /** @param list<string> $paragraphs */
    protected static function blocks(array $paragraphs): string
    {
        $out = '';
        foreach ($paragraphs as $paragraph) {
            $out .= "<!-- wp:paragraph -->\n<p>" . $paragraph . "</p>\n<!-- /wp:paragraph -->\n\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<int> $categories
     */
    protected function post(array $fields = [], array $categories = [self::NEWS]): \WP_Post
    {
        return \WpStub::post($fields + [
            'post_title' => 'Council agrees town plan',
            'post_content' => self::blocks([self::words(300), self::words(300, 'harbour')]),
        ], $categories);
    }

    protected function record(int $postId): AudioRecord
    {
        return new AudioRecord($this->plugin->records()->get($postId));
    }

    protected function trigger(int $postId, string $reason = 'publish', array $options = []): string
    {
        return $this->plugin->queue()->trigger($postId, $reason, $options);
    }

    protected function run_(int $postId): string
    {
        return $this->plugin->generator()->run($postId);
    }

    /** Publish-time trigger, run the job, approve: a post with live audio. */
    protected function approvedPost(array $fields = [], array $categories = [self::NEWS]): \WP_Post
    {
        $post = $this->post($fields, $categories);
        $this->assertSame('queued', $this->trigger($post->ID, 'generate'));
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertTrue($this->plugin->review()->approve($post->ID, self::EDITOR));

        return $post;
    }

    protected function edit(\WP_Post $post, string $content): void
    {
        $post->post_content = $content;
        $this->clock->advance(60);
        $post->post_modified_gmt = gmdate('Y-m-d H:i:s', $this->clock->now());
    }

    /** @return array<string, mixed> */
    protected function today(): array
    {
        return $this->ledger->usage($this->plugin->days()->today());
    }
}
