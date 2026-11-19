<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Admin\EditorPanel;
use IndianOpinion\ReadAloud\Admin\PostsList;
use IndianOpinion\ReadAloud\Admin\SettingsPage;
use IndianOpinion\ReadAloud\Admin\StatusPage;
use IndianOpinion\ReadAloud\Audio\Mp3;
use IndianOpinion\ReadAloud\Cli\Command;
use IndianOpinion\ReadAloud\Exception\AccountException;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Rest\Routes;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Tests\Support\FakeProvider;
use IndianOpinion\ReadAloud\Tests\Support\MemoryLedger;
use IndianOpinion\ReadAloud\Uninstaller;

/**
 * PRD 12.14, cases 102 to 109, section 8, 6.5 and 5.6.
 */
final class AdminTest extends TestCase
{
    private function request(string $method, int $postId, bool $nonce = true): \WP_REST_Request
    {
        $request = new \WP_REST_Request($method, '/read-aloud/v1/posts/' . $postId);
        $request->set_param('id', $postId);
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }

        return $request;
    }

    /** Test 96 (cases 102 to 105). */
    public function testSurfacesUseRestRoutesWithChecks(): void
    {
        $this->plugin->onRestInit();
        foreach (['/posts/(?P<id>\d+)', '/posts/(?P<id>\d+)/generate', '/posts/(?P<id>\d+)/approve', '/posts/(?P<id>\d+)/reject', '/posts/(?P<id>\d+)/audio', '/posts/(?P<id>\d+)/setting', '/status'] as $route) {
            $this->assertArrayHasKey(Routes::NS . $route, \WpStub::$routes);
        }
        $editorJs = (string) file_get_contents(dirname(__DIR__) . '/assets/editor.js');
        $adminJs = (string) file_get_contents(dirname(__DIR__) . '/assets/admin.js');
        foreach (['/generate', '/approve', '/reject', '/audio', '/setting'] as $path) {
            $this->assertStringContainsString("'" . $path . "'", $editorJs);
        }
        $this->assertStringContainsString('X-WP-Nonce', $adminJs);

        $post = $this->post();
        $routes = new Routes($this->plugin);
        \WpStub::$currentUser = self::READER;
        $denied = $routes->canWritePost($this->request('POST', $post->ID));
        $this->assertInstanceOf(\WP_Error::class, $denied);
        $this->assertSame('read_aloud_forbidden', $denied->get_error_code());
        \WpStub::can(self::READER, ['edit_post' => [999]]);
        $this->assertInstanceOf(\WP_Error::class, $routes->canWritePost($this->request('POST', $post->ID)), 'edit_post on another post is not enough');
        \WpStub::$currentUser = self::EDITOR;
        $this->assertSame('read_aloud_bad_nonce', $routes->canWritePost($this->request('POST', $post->ID, false))->get_error_code());
        $this->assertTrue($routes->canWritePost($this->request('POST', $post->ID)));

        $setting = $this->request('POST', $post->ID);
        $setting->set_param('mode', 'off');
        $routes->setting($setting);
        $this->assertSame('off', $this->plugin->records()->mode($post->ID));

        $html = (new EditorPanel($this->plugin))->metaBoxHtml($post);
        $this->assertStringContainsString('name="read_aloud_mode"', $html);
        $this->assertStringContainsString('data-read-aloud-action="generate"', $html);
        $this->assertStringContainsString('data-read-aloud-action="remove"', $html);

        $list = new PostsList($this->plugin);
        $this->assertArrayHasKey('read_aloud', $list->columns([]));
        $this->assertSame(['read_aloud_approve', 'read_aloud_generate', 'read_aloud_off'], array_keys($list->bulkActions([])));
        \WpStub::$currentUser = self::READER;
        $redirect = $list->handleBulk('edit.php', 'read_aloud_generate', [$post->ID]);
        $this->assertStringContainsString('read_aloud_done=0', $redirect);

        $public = $routes->getPost($this->request('GET', $post->ID, false));
        $this->assertSame(['ready' => false, 'url' => null, 'mime' => null, 'duration' => null], $public->get_data());
        $status = $routes->canManage(new \WP_REST_Request('GET', '/status'));
        $this->assertInstanceOf(\WP_Error::class, $status);
    }

    /** Test 97 (case 106). */
    public function testSettingsValidateAndSanitise(): void
    {
        $settings = $this->plugin->settings();
        [$clean, $errors] = $settings->sanitize([
            'cap_chars' => '12000', 'cap_generations' => '7', 'debounce_minutes' => '5', 'max_wait_minutes' => '30',
            'min_chars' => '100', 'max_chars' => '20000', 'grace_hours' => '24', 'concurrency' => '2', 'request_timeout' => '30',
            'stale_policy' => 'note', 'insert' => 'after', 'label' => '<b>Listen</b>', 'intro' => 'Hello', 'outro' => 'Bye',
            'disclosure' => 'A computer voice.', 'price_per_million' => '15.5', 'cap_money' => '2',
            'post_types' => ['post', 'page'], 'categories' => ['5', '6', 'x'], 'skip_blocks' => "core/code\ncore/quote",
            'lexicon' => "NHS = N H S\ngif = jif (i)", 'voice_map' => 'en_GB | v1 | m | 1.25 | wav',
            '_present' => '1', 'speed_buttons' => '1', 'download_link' => '1', 'feed_enclosure' => '1', 'keep_tables' => '1',
            'keep_captions' => '1', 'include_children' => '1', 'chunk_cache' => '', 'keep_files' => '1', 'show_player' => '1',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame(12000, $clean['cap_chars']);
        $this->assertSame('Listen', $clean['label']);
        $this->assertSame([5, 6], $clean['categories']);
        $this->assertSame(['core/code', 'core/quote'], $clean['skip_blocks']);
        $this->assertSame('en-GB', $clean['voice_map'][0]['locale']);
        $this->assertSame(1.25, $clean['voice_map'][0]['speed']);
        $this->assertFalse($clean['lexicon'][1]['case_sensitive']);
        $this->assertTrue($clean['speed_buttons']);
        $this->assertFalse($clean['chunk_cache']);

        [$kept, $bad] = $settings->sanitize([
            'cap_chars' => '-1', 'concurrency' => '9', 'stale_policy' => 'loud', 'insert' => 'top', 'price_per_million' => 'cheap',
            'post_types' => ['nope'], 'skip_blocks' => 'Not A Block', 'voice_map' => 'english | | | 9 | flac', 'provider' => 'missing',
            'debounce_minutes' => '1.5',
        ]);
        $this->assertGreaterThanOrEqual(10, count($bad));
        $this->assertContains('Concurrency must be between 1 and 4.', $bad);
        $this->assertSame(50000, $kept['cap_chars']);
        $this->assertSame(1, $kept['concurrency']);
        $this->assertSame('hide', $kept['stale_policy']);
        $this->assertSame('fake', $kept['provider']);
    }

    /** Test 98 (case 107). */
    public function testKeyNeverShown(): void
    {
        $key = 'sk-secret-key-0123456789abcdef';
        $page = new SettingsPage($this->plugin);
        $fields = ['endpoint' => 'https://speech.example.test/v1', 'body_template' => '{"input":"{text}"}', 'response_mode' => 'binary', 'max_chars' => '4000', 'formats' => 'mp3'];
        $this->assertSame([], $page->save(['provider' => 'http', 'provider_fields' => $fields, 'api_key' => $key, 'enabled' => '1', 'categories' => [self::NEWS]]));
        $this->assertSame($key, $this->plugin->settings()->apiKey());
        \WpStub::$currentUser = self::ADMIN;
        $html = $page->html();
        $this->assertStringNotContainsString($key, $html);
        $this->assertStringContainsString('A key is saved', $html);
        $this->assertSame([], $page->save(['provider' => 'http', 'provider_fields' => $fields, 'api_key' => '', 'enabled' => '1', 'categories' => [self::NEWS]]));
        $this->assertSame($key, $this->plugin->settings()->apiKey(), 'an empty field keeps the key');

        $plugin = new \IndianOpinion\ReadAloud\Plugin($this->clock, $this->ledger);
        \IndianOpinion\ReadAloud\Plugin::setInstance($plugin);
        \WpStub::respond(['response' => ['code' => 401], 'body' => 'Invalid key ' . $key]);
        $post = $this->post();
        $plugin->queue()->trigger($post->ID, 'generate');
        $this->assertSame('held', $plugin->generator()->run($post->ID));
        $this->assertStringNotContainsString($key, (string) json_encode(get_option('read_aloud_events')));
        $this->assertStringNotContainsString($key, (string) json_encode($plugin->records()->get($post->ID)));
        $this->assertStringNotContainsString($key, (string) json_encode($plugin->editorState($post)));
        $this->assertStringNotContainsString($key, (string) json_encode($plugin->statusData()));
    }

    /** Test 98 (case 107): the constant. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testKeyConstantDisablesField(): void
    {
        define('READ_ALOUD_API_KEY', 'sk-from-constant-123456');
        $this->settings(['provider' => 'http']);
        \WpStub::$currentUser = self::ADMIN;
        $html = (new SettingsPage($this->plugin))->html();
        $this->assertStringContainsString('name="read_aloud[api_key]" value="" class="regular-text" disabled', $html);
        $this->assertStringNotContainsString('sk-from-constant-123456', $html);
        $this->assertSame('sk-from-constant-123456', $this->plugin->settings()->apiKey());
    }

    /** Test 99 (case 108). */
    public function testTestVoiceCountsAndRespectsKillSwitch(): void
    {
        $result = $this->plugin->generator()->testVoice();
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, $this->today()['generations']);
        $this->assertSame(mb_strlen(\IndianOpinion\ReadAloud\Jobs\Generator::TEST_TEXT), $this->today()['chars']);
        $this->assertSame(100, mb_strlen(\IndianOpinion\ReadAloud\Jobs\Generator::TEST_TEXT));
        $this->plugin->guard()->setEnabled(false, 'test');
        $calls = count($this->provider->requests);
        $this->assertSame('disabled', $this->plugin->generator()->testVoice()['code']);
        $this->assertSame($calls, count($this->provider->requests));
    }

    /** Test 100 (case 109). */
    public function testStatusScreen(): void
    {
        $this->approvedPost();
        $this->trigger($this->approvedPost(['post_title' => 'Two'])->ID, 'update');
        \WpStub::$currentUser = self::ADMIN;
        $html = (new StatusPage($this->plugin))->html();
        foreach (['Generation:', 'Account hold:', 'Characters', 'Generations', 'Last 30 days', 'jobs waiting', 'Saves without resynthesis (30 days): 1', 'approved', 'Recent events'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    /** Test 101 (section 8). */
    public function testProviderCannotPlantHtmlOrPhp(): void
    {
        $this->provider->script = [fn (SpeechRequest $r) => FakeProvider::bytes($r, '<?php echo 1; ?>' . str_repeat('A', 200000))];
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('retrying', $this->run_($post->ID));
        $files = [];
        if (is_dir($this->uploads . '/read-aloud')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploads . '/read-aloud', \FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = (string) $file;
            }
        }
        $this->assertSame([], $files);
        $tmp = $this->dir . '/evil.mp3';
        file_put_contents($tmp, '<html>' . str_repeat('x', 5000));
        try {
            $this->plugin->store()->put($tmp, '2026/11/1-0123456789ab.mp3', 'audio/mpeg');
            $this->fail('HTML bytes must not be stored');
        } catch (\IndianOpinion\ReadAloud\Exception\StorageException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }
        file_put_contents($tmp, Mp3::silence(3));
        $this->expectException(\IndianOpinion\ReadAloud\Exception\StorageException::class);
        $this->plugin->store()->put($tmp, '2026/11/1-0123456789ab.php', 'audio/mpeg');
    }

    /** Test 102 (section 6.5). */
    public function testUninstall(): void
    {
        $post = $this->approvedPost();
        $dir = $this->uploads . '/read-aloud';
        $this->assertDirectoryExists($dir);
        $this->plugin->activate();
        Uninstaller::run(fn () => $this->ledger);
        foreach (Uninstaller::OPTIONS as $option) {
            $this->assertFalse(get_option($option), $option);
        }
        $this->assertSame('', get_post_meta($post->ID, '_read_aloud', true));
        $this->assertSame([], \WpStub::$cron);
        $this->assertTrue($this->ledger->uninstalled);
        $this->assertDirectoryDoesNotExist($dir);

        $this->settings(['keep_files' => true]);
        mkdir($dir . '/2026', 0775, true);
        Uninstaller::run(fn () => new MemoryLedger());
        $this->assertDirectoryExists($dir, 'kept with the setting');

        \WpStub::$multisite = true;
        \WpStub::$sites = [1, 2];
        foreach ([1, 2] as $blog) {
            \WpStub::$blog = $blog;
            update_option(Settings::OPTION, ['keep_files' => true]);
            update_option('read_aloud_events', [1]);
            update_post_meta(5, '_read_aloud', '{}');
        }
        \WpStub::$blog = 1;
        Uninstaller::run(fn () => new MemoryLedger());
        foreach ([1, 2] as $blog) {
            \WpStub::$blog = $blog;
            $this->assertFalse(get_option('read_aloud_events'));
            $this->assertSame('', get_post_meta(5, '_read_aloud', true));
        }
    }

    /** Test 102 (section 6.5): the keep constant. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testUninstallKeepConstant(): void
    {
        define('READ_ALOUD_KEEP_FILES', true);
        mkdir($this->uploads . '/read-aloud/2026', 0775, true);
        Uninstaller::run(fn () => new MemoryLedger());
        $this->assertDirectoryExists($this->uploads . '/read-aloud');
    }

    private function exitCode(callable $call): int
    {
        try {
            $call();
        } catch (\WpCliExit $e) {
            return $e->getCode();
        }

        return 0;
    }

    /** Test 103 (section 5.6). */
    public function testCliCommandsAndExitCodes(): void
    {
        $cli = new Command($this->plugin);
        $post = $this->post();
        $this->assertSame(0, $this->exitCode(fn () => $cli->generate([(string) $post->ID], [])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->text([(string) $post->ID], [])));
        $this->assertContains('content hash: ' . $this->plugin->planner()->plan($post)->contentHash, \WP_CLI::$lines);
        $this->assertSame(0, $this->exitCode(fn () => $cli->status([], [])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->status([(string) $post->ID], ['format' => 'json'])));
        $this->assertSame(1, $this->exitCode(fn () => $cli->status(['abc'], [])));

        $cli->pause([], []);
        $this->assertSame(3, $this->exitCode(fn () => $cli->generate([(string) $post->ID], ['force' => true])));
        $this->clock->advance(700);
        $this->assertSame(3, $this->exitCode(fn () => $cli->run([], [])));
        $cli->resume([], []);

        $this->settings(['cap_generations' => 0]);
        $this->assertSame(4, $this->exitCode(fn () => $cli->run([], [])));
        $this->settings(['cap_generations' => 20]);

        $this->clock->advance(86400);
        $this->provider->script = [new AccountException('401', 401)];
        $this->assertSame(5, $this->exitCode(fn () => $cli->run([], [])));
        $this->assertSame(5, $this->exitCode(fn () => $cli->generate([(string) $post->ID], [])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->clear_hold([], [])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->run([], ['max' => '5', 'time' => '60'])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->usage([], ['days' => '3'])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->reject([(string) $post->ID], ['user' => (string) self::EDITOR])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->remove([(string) $post->ID], [])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->verify([], [])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->prune([], ['dry-run' => true])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->cancel_backfill([], [])));
        $this->assertSame(0, $this->exitCode(fn () => $cli->backfill([], ['dry-run' => true])));
    }
}
