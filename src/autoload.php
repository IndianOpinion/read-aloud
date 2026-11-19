<?php

/**
 * Autoloader for installs without Composer. One require of this file is
 * enough; the plugin file does it.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\ReadAloud\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/functions.php';
