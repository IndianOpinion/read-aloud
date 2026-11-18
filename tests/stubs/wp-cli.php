<?php

declare(strict_types=1);

namespace WP_CLI\Utils;

// phpcs:disable PSR1.Files.SideEffects, Squiz.Functions.GlobalFunction

/**
 * @param list<array<string, mixed>> $items
 * @param list<string>|string $fields
 */
function format_items(string $format, array $items, array|string $fields): void
{
    foreach ($items as $item) {
        \WP_CLI::$items[] = $item;
    }
}
