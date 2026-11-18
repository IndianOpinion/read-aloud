<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Admin;

use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Review\Review;

/**
 * The "Audio" column and the bulk actions on the posts list. Core checks
 * the bulk-posts nonce; each post is checked for the review capability.
 */
final class PostsList
{
    public const COLUMN = 'read_aloud';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'hookTypes']);
        add_action('admin_notices', [$this, 'bulkNotice']);
    }

    public function hookTypes(): void
    {
        foreach ($this->plugin->settings()->strings('post_types') as $type) {
            add_filter('manage_' . $type . '_posts_columns', [$this, 'columns']);
            add_action('manage_' . $type . '_posts_custom_column', [$this, 'column'], 10, 2);
            add_filter('bulk_actions-edit-' . $type, [$this, 'bulkActions']);
            add_filter('handle_bulk_actions-edit-' . $type, [$this, 'handleBulk'], 10, 3);
        }
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $columns[self::COLUMN] = __('Audio', 'read-aloud');

        return $columns;
    }

    public function column(string $column, int $postId): void
    {
        if ($column !== self::COLUMN) {
            return;
        }
        $record = $this->plugin->records()->record($postId);
        echo esc_html($record === null ? '' : $record->status);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function bulkActions(array $actions): array
    {
        $actions['read_aloud_approve'] = __('Approve audio', 'read-aloud');
        $actions['read_aloud_generate'] = __('Generate audio', 'read-aloud');
        $actions['read_aloud_off'] = __('Turn audio off', 'read-aloud');

        return $actions;
    }

    /**
     * @param list<int|string> $postIds
     */
    public function handleBulk(string $redirect, string $action, array $postIds): string
    {
        if (!in_array($action, ['read_aloud_approve', 'read_aloud_generate', 'read_aloud_off'], true)) {
            return $redirect;
        }
        $done = 0;
        foreach ($postIds as $postId) {
            $postId = (int) $postId;
            if (!Review::canReview($postId)) {
                continue;
            }
            if ($action === 'read_aloud_approve') {
                $done += $this->plugin->review()->approve($postId, get_current_user_id()) === true ? 1 : 0;
            } elseif ($action === 'read_aloud_generate') {
                $done += $this->plugin->queue()->trigger($postId, 'generate', ['force' => true, 'fresh' => true]) === 'queued' ? 1 : 0;
            } else {
                $this->plugin->records()->setMode($postId, Records::MODE_OFF);
                $this->plugin->queue()->trigger($postId, 'setting');
                $done++;
            }
        }

        return add_query_arg(['read_aloud_bulk' => $action, 'read_aloud_done' => $done], $redirect);
    }

    public function bulkNotice(): void
    {
        if (!isset($_GET['read_aloud_bulk'], $_GET['read_aloud_done'])) {
            return;
        }
        $done = (int) $_GET['read_aloud_done'];
        printf('<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html(sprintf(__('Read aloud: %d posts changed.', 'read-aloud'), $done)));
    }
}
