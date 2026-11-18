<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Storage;

use IndianOpinion\ReadAloud\Audio\AudioFormat;
use IndianOpinion\ReadAloud\Exception\StorageException;

/**
 * Stores files under <uploads base>/read-aloud/.
 *
 * Files are written to a temporary name in the target directory and renamed
 * into place, so a reader never fetches a half-written file.
 */
final class UploadsStore implements AudioStore
{
    public const DIR = 'read-aloud';

    private string $baseDir;
    private string $baseUrl;

    public function __construct(?string $baseDir = null, ?string $baseUrl = null)
    {
        if ($baseDir === null || $baseUrl === null) {
            $uploads = wp_upload_dir(null, false);
            $baseDir ??= rtrim((string) $uploads['basedir'], '/') . '/' . self::DIR;
            $baseUrl ??= rtrim((string) $uploads['baseurl'], '/') . '/' . self::DIR;
        }
        $this->baseDir = rtrim($baseDir, '/');
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public static function validKey(string $key): bool
    {
        if (preg_match('~^\d{4}/\d{2}/\d+-[0-9a-f]{12}\.([a-z0-9]+)$~', $key, $m) !== 1) {
            return preg_match('~^test/[0-9a-f]{16}\.([a-z0-9]+)$~', $key, $m) === 1 && AudioFormat::isKnown($m[1]);
        }

        return AudioFormat::isKnown($m[1]);
    }

    private function path(string $key): string
    {
        if (!self::validKey($key)) {
            throw new StorageException('Refusing an unexpected file key.');
        }

        return $this->baseDir . '/' . $key;
    }

    public function put(string $tmpPath, string $key, string $mimeType): StoredFile
    {
        $target = $this->path($key);
        $extension = pathinfo($key, PATHINFO_EXTENSION);
        if (AudioFormat::mime($extension) !== $mimeType) {
            throw new StorageException('The file extension does not match its MIME type.');
        }
        $handle = is_file($tmpPath) ? fopen($tmpPath, 'rb') : false;
        if ($handle === false) {
            throw new StorageException('The audio file to store is missing.');
        }
        $head = (string) fread($handle, 16);
        fclose($handle);
        if (!AudioFormat::magicMatches($head, $extension)) {
            throw new StorageException('The file content does not match its extension.');
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            throw new StorageException('The audio directory cannot be created.');
        }
        if (!is_writable($directory)) {
            throw new StorageException('The audio directory is not writable.');
        }
        if (is_file($target)) {
            if (hash_file('sha256', $target) === hash_file('sha256', $tmpPath)) {
                return new StoredFile($key, $this->url($key), (int) filesize($target));
            }
            throw new StorageException('A different file already exists under this URL; it will not be overwritten.');
        }
        $partial = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        if (dirname($tmpPath) === $directory) {
            $partial = $tmpPath;
        } elseif (!copy($tmpPath, $partial)) {
            throw new StorageException('Writing the audio file failed (disk full?).');
        }
        if (!rename($partial, $target)) {
            if (is_file($partial)) {
                unlink($partial);
            }
            throw new StorageException('Renaming the audio file into place failed.');
        }
        if ($partial !== $tmpPath && is_file($tmpPath)) {
            unlink($tmpPath);
        }
        $bytes = filesize($target);

        return new StoredFile($key, $this->url($key), $bytes === false ? 0 : $bytes);
    }

    public function url(string $key): string
    {
        return $this->baseUrl . '/' . $key;
    }

    public function exists(string $key): bool
    {
        return self::validKey($key) && is_file($this->baseDir . '/' . $key);
    }

    public function delete(string $key): bool
    {
        if (!self::validKey($key)) {
            return false;
        }
        $path = $this->baseDir . '/' . $key;

        return is_file($path) && unlink($path);
    }

    public function size(string $key): ?int
    {
        if (!$this->exists($key)) {
            return null;
        }
        $size = filesize($this->baseDir . '/' . $key);

        return $size === false ? null : $size;
    }

    /**
     * Every stored key, for the prune command.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($this->baseDir))), '/');
            if (self::validKey($relative)) {
                $out[] = $relative;
            }
        }
        sort($out);

        return $out;
    }
}
