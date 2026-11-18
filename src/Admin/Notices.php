<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Admin;

use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Review\Review;

/**
 * Admin notices for an account hold and for caps at 80 percent or reached.
 */
final class Notices
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        echo $this->html(); // phpcs:ignore -- escaped in html().
    }

    public function html(): string
    {
        if (!Review::canManage()) {
            return '';
        }
        $out = '';
        $hold = $this->plugin->guard()->hold();
        if ($hold !== null) {
            $out .= '<div class="notice notice-error"><p>' . esc_html(sprintf(
                __('Read aloud is on hold: the speech provider refused the account (%s). Check the key and credit, then save the provider settings or clear the hold.', 'read-aloud'),
                $hold['code']
            )) . '</p></div>';
        }
        $flags = $this->plugin->ledger()->usage($this->plugin->days()->today())['flags'];
        if (($flags & (Ledger::FLAG_REACHED_CHARS | Ledger::FLAG_REACHED_GENERATIONS | Ledger::FLAG_REACHED_MONEY)) !== 0) {
            $out .= '<div class="notice notice-warning"><p>' . esc_html__('Read aloud reached a daily cap. Waiting jobs move to tomorrow.', 'read-aloud') . '</p></div>';
        } elseif (($flags & (Ledger::FLAG_WARN_CHARS | Ledger::FLAG_WARN_GENERATIONS | Ledger::FLAG_WARN_MONEY)) !== 0) {
            $out .= '<div class="notice notice-warning"><p>' . esc_html__('Read aloud has used 80 percent of a daily cap.', 'read-aloud') . '</p></div>';
        }

        return $out;
    }
}
