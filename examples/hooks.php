<?php

/**
 * Example hooks: purge a page cache, skip a category, pronounce a name,
 * and alert when a cap is reached.
 */

declare(strict_types=1);

// Purge your page cache or CDN when the player changes.
add_action('read_aloud_player_changed', static function (int $postId): void {
    do_action('my_cache_purge_url', get_permalink($postId));
});

// Never read posts in the "Podcasts" category, which already have audio.
add_filter('read_aloud_is_eligible', static function (bool $eligible, WP_Post $post): bool {
    return $eligible && !has_category('podcasts', $post);
}, 10, 2);

// Read only the first 20,000 characters of very long reports? No: raise the limit instead.
add_filter('read_aloud_max_chars', static fn (int $max, WP_Post $post): int => has_category('reports', $post) ? 120000 : $max, 10, 2);

// Mail the site admin when a daily cap is reached.
add_action('read_aloud_cap_reached', static function (string $cap, int $limit, int $used, string $day): void {
    wp_mail((string) get_option('admin_email'), 'Read aloud cap reached', sprintf('%s cap of %d reached on %s.', $cap, $limit, $day));
}, 10, 4);
