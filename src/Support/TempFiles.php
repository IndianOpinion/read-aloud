<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Support;

use IndianOpinion\ReadAloud\Exception\StorageException;

/**
 * Temporary files for provider output and joins.
 */
final class TempFiles
{
    public static function directory(): string
    {
        $dir = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();

        return rtrim($dir, '/\\');
    }

    /**
     * @throws StorageException
     */
    public static function create(string $extension, ?string $directory = null): string
    {
        $directory ??= self::directory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new StorageException('Temporary directory cannot be created.');
        }
        $name = $directory . '/read-aloud-' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/', '', $extension) . '.part';
        $handle = fopen($name, 'xb');
        if ($handle === false) {
            throw new StorageException('Temporary file cannot be created.');
        }
        fclose($handle);

        return $name;
    }

    public static function remove(?string $path): void
    {
        if ($path !== null && $path !== '' && is_file($path)) {
            unlink($path);
        }
    }
}
