<?php

/**
 * Plugin Name:       Read Aloud
 * Plugin URI:        https://github.com/IndianOpinion/read-aloud
 * Description:       Turns a post into an audio file once, through any text-to-speech API, after an editor approves it. Opt-in, capped, with a kill switch.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       read-aloud
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('READ_ALOUD_VERSION')) {
    return;
}

define('READ_ALOUD_VERSION', '0.1.0');
define('READ_ALOUD_FILE', __FILE__);

if (!class_exists(\IndianOpinion\ReadAloud\Plugin::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\ReadAloud\Plugin::instance()->register(__FILE__);
