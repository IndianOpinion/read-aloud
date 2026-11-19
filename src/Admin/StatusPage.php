<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Admin;

use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Review\Review;

/**
 * Tools > Read aloud: kill switch and hold state, today's counters against
 * each cap, the last 30 days, the queue, counts per status, recent events
 * and the saves-without-resynthesis counter.
 */
final class StatusPage
{
    public const SLUG = 'read-aloud-status';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Read aloud status', 'read-aloud'),
            __('Read aloud', 'read-aloud'),
            (string) apply_filters('read_aloud_capability', 'manage_options', 'settings'),
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!Review::canManage()) {
            wp_die(esc_html__('You cannot see this page.', 'read-aloud'), 403);
        }
        echo $this->html(); // phpcs:ignore -- every part is escaped in html().
    }

    public function html(): string
    {
        $data = $this->plugin->statusData();
        $today = (array) $data['today'];
        $caps = (array) $data['caps'];
        $out = '<div class="wrap"><h1>' . esc_html__('Read aloud status', 'read-aloud') . '</h1>';
        $state = $data['enabled'] ? __('on', 'read-aloud') : __('off', 'read-aloud');
        if ($data['constant_disabled']) {
            $state .= ' ' . __('(READ_ALOUD_DISABLED is defined)', 'read-aloud');
        }
        $out .= '<p><strong>' . esc_html__('Generation:', 'read-aloud') . '</strong> ' . esc_html($state) . '</p>';
        $hold = $data['held'];
        if (is_array($hold)) {
            $out .= '<p><strong>' . esc_html__('Account hold:', 'read-aloud') . '</strong> ' . esc_html(sprintf('%s (%s) %s', (string) $hold['provider'], (string) $hold['code'], (string) $hold['message'])) . '</p>';
        } else {
            $out .= '<p><strong>' . esc_html__('Account hold:', 'read-aloud') . '</strong> ' . esc_html__('none', 'read-aloud') . '</p>';
        }

        $out .= '<h2>' . esc_html(sprintf(__('Today (%s)', 'read-aloud'), (string) $today['day'])) . '</h2><table class="widefat striped"><thead><tr><th>'
            . esc_html__('Cap', 'read-aloud') . '</th><th>' . esc_html__('Used', 'read-aloud') . '</th><th>' . esc_html__('Limit', 'read-aloud') . '</th></tr></thead><tbody>';
        $out .= '<tr><td>' . esc_html__('Characters', 'read-aloud') . '</td><td>' . esc_html(number_format((int) $today['chars'])) . ' (+' . esc_html(number_format((int) $today['reserved_chars'])) . ' ' . esc_html__('reserved', 'read-aloud') . ')</td><td>' . esc_html(number_format((int) $caps['chars'])) . '</td></tr>';
        $out .= '<tr><td>' . esc_html__('Generations', 'read-aloud') . '</td><td>' . esc_html((string) $today['generations']) . '</td><td>' . esc_html((string) $caps['generations']) . '</td></tr>';
        $out .= '<tr><td>' . esc_html__('Money', 'read-aloud') . '</td><td>' . esc_html((string) $today['money']) . '</td><td>' . esc_html($caps['money'] === null ? __('off', 'read-aloud') : (string) $caps['money']) . '</td></tr>';
        $out .= '</tbody></table>';

        $out .= '<h2>' . esc_html__('Queue', 'read-aloud') . '</h2><p>' . esc_html(sprintf(
            __('%1$d jobs waiting, about %2$s characters. Estimated days to drain at the current cap: %3$s.', 'read-aloud'),
            (int) $data['queue_length'],
            number_format((int) $data['queued_chars']),
            $data['days_to_drain'] === null ? __('never (cap is 0)', 'read-aloud') : (string) $data['days_to_drain']
        )) . '</p>';
        $out .= '<p>' . esc_html(sprintf(__('Saves without resynthesis (30 days): %d', 'read-aloud'), (int) $data['saves_without_resynthesis'])) . '</p>';

        $out .= '<h2>' . esc_html__('Records by status', 'read-aloud') . '</h2><table class="widefat striped"><tbody>';
        foreach ((array) $data['counts'] as $status => $count) {
            $out .= '<tr><td>' . esc_html((string) $status) . '</td><td>' . esc_html((string) $count) . '</td></tr>';
        }
        $out .= '</tbody></table>';

        $out .= '<h2>' . esc_html__('Last 30 days', 'read-aloud') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Day', 'read-aloud') . '</th><th>'
            . esc_html__('Characters', 'read-aloud') . '</th><th>' . esc_html__('Generations', 'read-aloud') . '</th><th>' . esc_html__('Saves without resynthesis', 'read-aloud') . '</th></tr></thead><tbody>';
        foreach ((array) $data['history'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out .= '<tr><td>' . esc_html((string) $row['day']) . '</td><td>' . esc_html(number_format((int) $row['chars'])) . '</td><td>' . esc_html((string) $row['generations']) . '</td><td>' . esc_html((string) $row['saves_skipped']) . '</td></tr>';
        }
        $out .= '</tbody></table>';

        $out .= '<h2>' . esc_html__('Recent events', 'read-aloud') . '</h2><table class="widefat striped"><tbody>';
        foreach ((array) $data['events'] as $event) {
            if (!is_array($event)) {
                continue;
            }
            $details = [];
            foreach ((array) $event['data'] as $key => $value) {
                $details[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
            $out .= '<tr><td>' . esc_html(wp_date('Y-m-d H:i', (int) $event['t']) ?: '') . '</td><td>' . esc_html((string) $event['type'])
                . '</td><td>' . esc_html($event['post'] > 0 ? '#' . $event['post'] : '') . '</td><td>' . esc_html(implode(' ', $details)) . '</td></tr>';
        }

        return $out . '</tbody></table></div>';
    }
}
