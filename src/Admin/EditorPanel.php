<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Admin;

use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Review\Review;
use IndianOpinion\ReadAloud\Rest\Routes;

/**
 * The "Read aloud" panel in the block editor (assets/editor.js) and the
 * same controls as a meta box in the classic editor. Both call the REST
 * routes, which check the capability on the post and the REST nonce.
 */
final class EditorPanel
{
    public const NONCE = 'read_aloud_meta_box';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditor']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueClassic']);
        add_action('save_post', [$this, 'saveMetaBox'], 10, 2);
    }

    private function supports(string $postType): bool
    {
        return in_array($postType, $this->plugin->settings()->strings('post_types'), true);
    }

    public function addMetaBox(string $postType, mixed $post = null): void
    {
        if (!$this->supports($postType)) {
            return;
        }
        add_meta_box('read-aloud', __('Read aloud', 'read-aloud'), [$this, 'renderMetaBox'], $postType, 'side', 'default', ['__back_compat_meta_box' => true]);
    }

    /** @return array<string, mixed> */
    private function scriptData(int $postId): array
    {
        return [
            'root' => esc_url_raw(rest_url(Routes::NS . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'postId' => $postId,
        ];
    }

    public function enqueueBlockEditor(): void
    {
        $post = get_post();
        if (!$post instanceof \WP_Post || !$this->supports($post->post_type) || !Review::canReview((int) $post->ID)) {
            return;
        }
        wp_enqueue_script('read-aloud-editor');
        wp_localize_script('read-aloud-editor', 'readAloudEditor', $this->scriptData((int) $post->ID));
    }

    public function enqueueClassic(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post || !$this->supports($post->post_type)) {
            return;
        }
        wp_enqueue_script('read-aloud-admin', plugins_url('assets/admin.js', READ_ALOUD_FILE), [], Plugin::VERSION, true);
        wp_localize_script('read-aloud-admin', 'readAloudAdmin', $this->scriptData((int) $post->ID));
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        echo $this->metaBoxHtml($post); // phpcs:ignore -- escaped in metaBoxHtml().
    }

    public function metaBoxHtml(\WP_Post $post): string
    {
        $state = $this->plugin->editorState($post);
        $mode = (string) $state['mode'];
        $out = wp_nonce_field(self::NONCE, 'read_aloud_meta_box_nonce', true, false);
        $out .= '<p><label for="read-aloud-mode">' . esc_html__('Audio for this post', 'read-aloud') . '</label><br><select id="read-aloud-mode" name="read_aloud_mode">';
        $inherit = sprintf(__('Inherit (from categories: %s)', 'read-aloud'), $state['inherit_resolves'] === 'on' ? __('on', 'read-aloud') : __('off', 'read-aloud'));
        foreach (['inherit' => $inherit, 'on' => __('On', 'read-aloud'), 'off' => __('Off', 'read-aloud')] as $value => $label) {
            $out .= '<option value="' . esc_attr($value) . '"' . selected($mode, $value, false) . '>' . esc_html($label) . '</option>';
        }
        $out .= '</select></p>';
        $status = (string) $state['status'];
        $out .= '<p><strong>' . esc_html__('Status:', 'read-aloud') . '</strong> ' . esc_html($status);
        if ((string) $state['reason'] !== '') {
            $out .= ' (' . esc_html((string) $state['reason']) . ')';
        }
        $out .= '</p>';
        if ((int) $state['estimated_chars'] > 0) {
            $out .= '<p>' . esc_html(sprintf(__('About %s characters.', 'read-aloud'), number_format((int) $state['estimated_chars']))) . '</p>';
        }
        if ((int) $state['next_attempt_at'] > 0) {
            $out .= '<p>' . esc_html(sprintf(__('Next attempt: %s', 'read-aloud'), (string) wp_date('Y-m-d H:i', (int) $state['next_attempt_at']))) . '</p>';
        }
        if ((string) $state['last_error_code'] !== '') {
            $out .= '<p>' . esc_html(sprintf(__('Last error: %1$s %2$s', 'read-aloud'), (string) $state['last_error_code'], (string) $state['last_error_message'])) . '</p>';
        }
        $pending = $state['pending'];
        if (is_array($pending)) {
            $out .= '<p><strong>' . esc_html__('Waiting for review', 'read-aloud') . '</strong></p>';
            $out .= '<audio controls preload="none" style="width:100%"><source src="' . esc_url((string) $pending['url']) . '" type="' . esc_attr((string) $pending['mime']) . '"></audio>';
            $out .= '<p>' . esc_html(sprintf(__('%1$s seconds, %2$s characters billed.', 'read-aloud'), number_format((float) $pending['duration'], 1), number_format((int) $pending['chars']))) . '</p>';
            $out .= '<details><summary>' . esc_html__('Text that was read', 'read-aloud') . '</summary><div style="max-height:12em;overflow:auto;white-space:pre-wrap">' . esc_html((string) $state['spoken_text']) . '</div></details>';
            $out .= '<p><button type="button" class="button button-primary" data-read-aloud-action="approve">' . esc_html__('Approve', 'read-aloud') . '</button> '
                . '<button type="button" class="button" data-read-aloud-action="reject">' . esc_html__('Reject', 'read-aloud') . '</button></p>';
        }
        $live = $state['live'];
        if (is_array($live)) {
            $out .= '<p>' . esc_html__('Live audio:', 'read-aloud') . '</p><audio controls preload="none" style="width:100%"><source src="' . esc_url((string) $live['url']) . '" type="' . esc_attr((string) $live['mime']) . '"></audio>';
        }
        $out .= '<p><button type="button" class="button" data-read-aloud-action="generate">' . esc_html__('Generate now', 'read-aloud') . '</button> '
            . '<button type="button" class="button-link button-link-delete" data-read-aloud-action="remove">' . esc_html__('Remove audio', 'read-aloud') . '</button></p>';
        $out .= '<p class="read-aloud-message" aria-live="polite"></p>';

        return $out;
    }

    public function saveMetaBox(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['read_aloud_meta_box_nonce'], $_POST['read_aloud_mode'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST['read_aloud_meta_box_nonce']));
        if (wp_verify_nonce($nonce, self::NONCE) === false || !Review::canReview($postId)) {
            return;
        }
        if (wp_is_post_revision($postId) !== false || wp_is_post_autosave($postId) !== false) {
            return;
        }
        $mode = sanitize_key(wp_unslash((string) $_POST['read_aloud_mode']));
        if (in_array($mode, [Records::MODE_INHERIT, Records::MODE_ON, Records::MODE_OFF], true)) {
            $this->plugin->records()->setMode($postId, $mode);
            $this->plugin->defer($postId, 'setting');
        }
    }
}
