<?php

/**
 * A stand-in for the one gen-queue function the plugin calls when the queue
 * is active. Loaded only inside a separate-process test.
 */

declare(strict_types=1);

/**
 * @param array<string, mixed> $args
 */
function gen_queue_register_type(string $type, callable $handler, array $args = []): void
{
    $GLOBALS['read_aloud_test_types'][$type] = [$handler, $args];
}
