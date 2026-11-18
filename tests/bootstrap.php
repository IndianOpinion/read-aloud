<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects

$root = sys_get_temp_dir() . '/read-aloud-tests-' . getmypid();
if (!is_dir($root . '/www')) {
    mkdir($root . '/www', 0775, true);
}
define('ABSPATH', $root . '/www/');
define('READ_ALOUD_FILE', dirname(__DIR__) . '/read-aloud.php');
define('READ_ALOUD_TEST_ROOT', $root);

require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/WpStub.php';
require __DIR__ . '/stubs/functions.php';
require __DIR__ . '/stubs/wp-cli.php';
require dirname(__DIR__) . '/vendor/autoload.php';

register_shutdown_function(static function () use ($root): void {
    \IndianOpinion\ReadAloud\Uninstaller::removeTree($root);
});
