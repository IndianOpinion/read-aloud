<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Support;

/**
 * Reads an option from the database, bypassing any persistent object cache.
 *
 * Used at decision points (kill switch, hold) so a cached "on" cannot keep
 * spending after an operator turned generation off.
 */
final class FreshOptions
{
    public static function get(string $name, mixed $default = false): mixed
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');

        return get_option($name, $default);
    }
}
