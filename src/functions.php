<?php

/**
 * Template and public functions. Declarations only.
 */

declare(strict_types=1);

use IndianOpinion\ReadAloud\Data\AudioRecord;
use IndianOpinion\ReadAloud\Ledger\CapPolicy;
use IndianOpinion\ReadAloud\Plugin;

if (!function_exists('read_aloud_player')) {
    /**
     * Player HTML for a post, or an empty string. Same markup as the block.
     */
    function read_aloud_player(int|WP_Post|null $post = null): string
    {
        return Plugin::instance()->player()->render($post);
    }
}

if (!function_exists('read_aloud_get')) {
    /** Read-only audio record of a post, or null. */
    function read_aloud_get(int $post_id): ?AudioRecord
    {
        return Plugin::instance()->records()->record($post_id);
    }
}

if (!function_exists('read_aloud_is_eligible')) {
    /** Full rule set of eligibility, including length and voice. */
    function read_aloud_is_eligible(int $post_id): bool
    {
        $post = get_post($post_id);

        return $post instanceof WP_Post && Plugin::instance()->planner()->plan($post)->eligible;
    }
}

if (!function_exists('read_aloud_spoken_text')) {
    /** What would be sent for a post, before the lexicon. */
    function read_aloud_spoken_text(int $post_id): string
    {
        $post = get_post($post_id);

        return $post instanceof WP_Post ? Plugin::instance()->planner()->spokenText($post) : '';
    }
}

if (!function_exists('read_aloud_queue')) {
    /** Queue a post. False when not eligible, or unchanged and not forced. */
    function read_aloud_queue(int $post_id, bool $force = false): bool
    {
        return Plugin::instance()->queue()->trigger($post_id, 'api', ['force' => $force, 'fresh' => $force]) === 'queued';
    }
}

if (!function_exists('read_aloud_enabled')) {
    /** Fresh read of the kill switch and the constant. */
    function read_aloud_enabled(): bool
    {
        return Plugin::instance()->guard()->enabled();
    }
}

if (!function_exists('read_aloud_usage')) {
    /**
     * Counters for a day (default today, site time zone).
     *
     * @return array{chars: int, generations: int, money: string|null, caps: array{chars: int, generations: int, money: string|null}}
     */
    function read_aloud_usage(?string $day = null): array
    {
        $plugin = Plugin::instance();
        $usage = $plugin->ledger()->usage($day ?? $plugin->days()->today());
        $caps = $plugin->capPolicy()->caps();
        $priced = $plugin->capPolicy()->priceMicros() !== null;

        return [
            'chars' => $usage['chars'],
            'generations' => $usage['generations'],
            'money' => $priced ? CapPolicy::fromMicros($usage['money']) : null,
            'caps' => [
                'chars' => $caps->chars,
                'generations' => $caps->generations,
                'money' => $caps->moneyMicros === null ? null : CapPolicy::fromMicros($caps->moneyMicros),
            ],
        ];
    }
}
