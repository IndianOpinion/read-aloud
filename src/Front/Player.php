<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Front;

use IndianOpinion\ReadAloud\Data\AudioRecord;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Jobs\Planner;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Storage\AudioStore;

/**
 * The reader-facing player (PRD 4.12). Rendering reads only the post and
 * its meta: no network call, no queueing, and no filesystem call unless
 * the optional existence check is turned on.
 */
final class Player
{
    public const BLOCK = 'read-aloud/player';
    public const SCRIPT = 'read-aloud-speed';

    public function __construct(
        private readonly Settings $settings,
        private readonly Records $records,
        private readonly Planner $planner,
        private readonly AudioStore $store
    ) {
    }

    /**
     * The record to play, or null when nothing should be shown.
     */
    public function playable(\WP_Post $post): ?AudioRecord
    {
        if (!$this->settings->bool('show_player')) {
            return null;
        }
        $record = $this->records->record((int) $post->ID);
        if ($record === null || !$record->hasLiveFile() || $record->status === Status::MISSING) {
            return null;
        }
        if (!$this->planner->base($post)['eligible']) {
            return null;
        }
        if ($record->isStale() && $this->settings->string('stale_policy') !== 'note') {
            return null;
        }
        if ($this->settings->bool('check_file_exists') && !$this->store->exists($record->fileKey)) {
            return null;
        }

        return $record;
    }

    /** @return array{label: string, disclosure: string, stale: string, download: string} */
    public function labels(): array
    {
        $label = $this->settings->string('label');
        $disclosure = $this->settings->string('disclosure');
        $labels = [
            'label' => $label !== '' ? $label : __('Listen to this article', 'read-aloud'),
            'disclosure' => $disclosure !== '' ? $disclosure : __('Read by a synthetic voice.', 'read-aloud'),
            'stale' => __('This audio was made from an earlier version of this article.', 'read-aloud'),
            'download' => __('Download the audio', 'read-aloud'),
        ];
        $filtered = apply_filters('read_aloud_player_labels', $labels);
        if (is_array($filtered)) {
            foreach ($labels as $key => $value) {
                if (isset($filtered[$key]) && is_string($filtered[$key])) {
                    $labels[$key] = $filtered[$key];
                }
            }
        }

        return $labels;
    }

    public static function formatDuration(float $seconds): string
    {
        $total = (int) round($seconds);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    public static function isoDuration(float $seconds): string
    {
        $total = (int) round($seconds);

        return sprintf('PT%dM%dS', intdiv($total, 60), $total % 60);
    }

    /** Player HTML for a post, or an empty string. */
    public function render(int|\WP_Post|null $post = null): string
    {
        $post = get_post($post);
        if (!$post instanceof \WP_Post) {
            return '';
        }
        $record = $this->playable($post);
        if ($record === null) {
            return '';
        }
        $labels = $this->labels();
        $url = $record->fileUrl !== '' ? $record->fileUrl : $this->store->url($record->fileKey);
        $html = '<figure class="read-aloud" data-read-aloud>';
        $html .= '<figcaption class="read-aloud__label">' . esc_html($labels['label']);
        if ($record->duration > 0) {
            $html .= ' <time class="read-aloud__duration" datetime="' . esc_attr(self::isoDuration($record->duration)) . '">'
                . esc_html(self::formatDuration($record->duration)) . '</time>';
        }
        $html .= '</figcaption>';
        $html .= '<audio class="read-aloud__audio" controls preload="none">'
            . '<source src="' . esc_url($url) . '" type="' . esc_attr($record->mime) . '">'
            . '</audio>';
        if ($record->isStale()) {
            $html .= '<p class="read-aloud__note">' . esc_html($labels['stale']) . '</p>';
        }
        if ($this->settings->bool('download_link')) {
            $html .= '<p class="read-aloud__download"><a href="' . esc_url($url) . '" download>' . esc_html($labels['download']) . '</a></p>';
        }
        if ($labels['disclosure'] !== '') {
            $html .= '<p class="read-aloud__disclosure">' . esc_html($labels['disclosure']) . '</p>';
        }
        $html .= '</figure>';
        if ($this->settings->bool('speed_buttons')) {
            wp_enqueue_script(self::SCRIPT);
        }
        $filtered = apply_filters('read_aloud_player_html', $html, $post, $record);

        return is_string($filtered) ? $filtered : $html;
    }

    /** the_content filter: insert before or after, never twice. */
    public function insert(string $content): string
    {
        $position = $this->settings->string('insert');
        if ($position === 'none' || is_feed() || !in_the_loop() || !is_main_query() || !is_singular()) {
            return $content;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post || has_block(self::BLOCK, $post) || str_contains($content, 'data-read-aloud')) {
            return $content;
        }
        $player = $this->render($post);
        if ($player === '') {
            return $content;
        }

        return $position === 'after' ? $content . $player : $player . $content;
    }

    /** rss2_item: an enclosure for approved, shown files, skipping outdated_settings. */
    public function enclosure(): void
    {
        if (!$this->settings->bool('feed_enclosure')) {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return;
        }
        $record = $this->playable($post);
        if ($record === null || $record->isStale() || $record->outdated || $record->status === Status::OUTDATED_SETTINGS) {
            return;
        }
        $url = $record->fileUrl !== '' ? $record->fileUrl : $this->store->url($record->fileKey);
        echo '<enclosure url="' . esc_url($url) . '" length="' . esc_attr((string) $record->bytes) . '" type="' . esc_attr($record->mime) . '" />' . "\n";
    }

    /**
     * Block render callback.
     *
     * @param array<string, mixed> $attributes
     */
    public function renderBlock(array $attributes, string $content = '', ?\WP_Block $block = null): string
    {
        $postId = $block !== null && isset($block->context['postId']) ? (int) $block->context['postId'] : (int) get_the_ID();

        return $this->render($postId > 0 ? $postId : null);
    }
}
