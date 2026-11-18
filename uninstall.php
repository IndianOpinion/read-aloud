<?php

/**
 * Runs when the plugin is deleted from the Plugins screen.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!class_exists(\IndianOpinion\ReadAloud\Uninstaller::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\ReadAloud\Uninstaller::run();
