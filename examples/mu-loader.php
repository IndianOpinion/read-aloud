<?php

/**
 * Plugin Name: Read Aloud (must-use loader)
 * Description: Loads read-aloud from wp-content/mu-plugins/read-aloud/.
 *
 * Copy this file to wp-content/mu-plugins/read-aloud-loader.php.
 */

declare(strict_types=1);

if (defined('ABSPATH') && is_file(__DIR__ . '/read-aloud/read-aloud.php')) {
    require_once __DIR__ . '/read-aloud/read-aloud.php';
}
