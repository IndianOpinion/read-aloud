<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Admin\EditorPanel;
use IndianOpinion\ReadAloud\Admin\PostsList;
use IndianOpinion\ReadAloud\Cli\Command;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Rest\Routes;
use IndianOpinion\ReadAloud\Settings\Settings;

/**
 * PRD 12.8, cases 66 to 71.
 */
final class ReviewTest extends TestCase
{
    private function generated(): \WP_Post
    {
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));

        return $post;
    }

    private function rest(string $method, int $postId, string $action, bool $nonce = true): mixed
    {
        $routes = new Routes($this->plugin);
        $request = new \WP_REST_Request($method, '/read-aloud/v1/posts/' . $postId . '/' . $action);
        $request->set_param('id', $postId);
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        $allowed = $routes->canWritePost($request);
        if ($allowed !== true) {
            return $allowed;
        }

        return match ($action) {
            'approve' => $routes->approve($request),
            'reject' => $routes->reject($request),
            'generate' => $routes->generate($request),
            'audio' => $routes->remove($request),
            default => null,
        };
    }

    /** Test 60 (case 66). */
    public function testPendingNeverRenderedForReadersButShownToEditor(): void
    {
        $post = $this->generated();
        $this->assertSame('', read_aloud_player($post->ID));
        \WpStub::$currentUser = self::EDITOR;
        $html = (new EditorPanel($this->plugin))->metaBoxHtml($post);
        $pending = $this->record($post->ID)->pending;
        $this->assertStringContainsString(esc_url($pending['file_url']), $html);
        $this->assertStringContainsString('Council agrees town plan.', $html);
        $this->assertStringContainsString('data-read-aloud-action="approve"', $html);
        $state = $this->plugin->editorState($post);
        $this->assertSame($pending['chars'], $state['pending']['chars']);
    }

    /** Test 61 (case 67). */
    public function testApproveNeedsRightsAndNonce(): void
    {
        $post = $this->generated();
        $approved = [];
        add_action('read_aloud_approved', function (int $postId, int $userId) use (&$approved): void {
            $approved[] = [$postId, $userId];
        }, 10, 2);

        \WpStub::$currentUser = self::READER;
        $result = $this->rest('POST', $post->ID, 'approve');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('read_aloud_forbidden', $result->get_error_code());

        \WpStub::$currentUser = self::EDITOR;
        $result = $this->rest('POST', $post->ID, 'approve', false);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('read_aloud_bad_nonce', $result->get_error_code());
        $this->assertSame(Status::PENDING_REVIEW, $this->record($post->ID)->status);

        $result = $this->rest('POST', $post->ID, 'approve');
        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $record = $this->record($post->ID);
        $this->assertSame(Status::APPROVED, $record->status);
        $this->assertSame(self::EDITOR, $record->approvedBy);
        $this->assertSame($this->clock->now(), $record->approvedAt);
        $this->assertSame([[$post->ID, self::EDITOR]], $approved);
    }

    /** Test 62 (case 68). */
    public function testRejectDeletesAndRemembersHash(): void
    {
        $post = $this->generated();
        $key = $this->record($post->ID)->pending['file_key'];
        $this->assertFileExists($this->uploads . '/read-aloud/' . $key);
        \WpStub::$currentUser = self::EDITOR;
        $this->assertInstanceOf(\WP_REST_Response::class, $this->rest('POST', $post->ID, 'reject'));
        $this->assertFileDoesNotExist($this->uploads . '/read-aloud/' . $key);
        $this->assertSame(Status::NONE, $this->record($post->ID)->status);
        $this->assertSame('rejected', $this->trigger($post->ID, 'update'));
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
        $this->edit($post, str_replace('council', 'committee', $post->post_content));
        $this->assertSame('queued', $this->trigger($post->ID, 'update'));
    }

    /** Test 63 (case 69). */
    public function testNoAutoApprovalAndBulkAndCliApprove(): void
    {
        $this->assertSame([], preg_grep('/approv/i', array_keys(Settings::baseDefaults())));
        $html = (new \IndianOpinion\ReadAloud\Admin\SettingsPage($this->plugin))->html();
        $this->assertStringNotContainsStringIgnoringCase('auto-approv', $html);
        $this->assertStringNotContainsStringIgnoringCase('automatic approv', $html);

        $a = $this->generated();
        $b = $this->post(['post_title' => 'Second story']);
        $this->trigger($b->ID, 'generate');
        $this->run_($b->ID);
        \WpStub::$currentUser = self::EDITOR;
        $redirect = (new PostsList($this->plugin))->handleBulk('edit.php', 'read_aloud_approve', [$a->ID]);
        $this->assertStringContainsString('read_aloud_done=1', $redirect);
        $this->assertSame(Status::APPROVED, $this->record($a->ID)->status);

        $command = new Command($this->plugin);
        try {
            $command->approve([(string) $b->ID], []);
            $this->fail('--user is required');
        } catch (\WpCliExit $e) {
            $this->assertSame(1, $e->getCode());
        }
        $command->approve([(string) $b->ID], ['user' => (string) self::EDITOR]);
        $this->assertSame(Status::APPROVED, $this->record($b->ID)->status);
    }

    /** Test 64 (case 70). */
    public function testStalePolicy(): void
    {
        $post = $this->approvedPost();
        $this->edit($post, str_replace('council', 'committee', $post->post_content));
        $this->trigger($post->ID, 'update');
        $this->assertSame('', read_aloud_player($post->ID), 'hide is the default');
        $this->settings(['stale_policy' => 'note']);
        $html = read_aloud_player($post->ID);
        $this->assertStringContainsString('This audio was made from an earlier version of this article.', $html);
        $this->settings(['stale_policy' => 'hide']);
        $this->clock->advance(700);
        $this->run_($post->ID);
        $this->plugin->review()->approve($post->ID, self::EDITOR);
        $this->assertNotSame('', read_aloud_player($post->ID));
    }

    /** Test 65 (case 71). */
    public function testOutdatedSettingsPlaysWithoutNote(): void
    {
        $post = $this->approvedPost();
        $this->settings(['voice_map' => [['locale' => 'en-GB', 'voice' => 'new-voice', 'model' => '', 'speed' => 1.0, 'format' => 'mp3']]]);
        $this->plugin->maintenance()->markOutdated();
        $this->assertSame(Status::OUTDATED_SETTINGS, $this->record($post->ID)->status);
        $html = read_aloud_player($post->ID);
        $this->assertNotSame('', $html);
        $this->assertStringNotContainsString('earlier version', $html);
    }
}
