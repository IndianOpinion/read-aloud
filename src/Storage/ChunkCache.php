<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Storage;

use IndianOpinion\ReadAloud\Exception\StorageException;

/**
 * Keeps synthesised chunks so a correction to one paragraph costs one
 * chunk, and so a job stopped half-way resumes from the first missing chunk.
 *
 * Layout: <dir>/<post id>/<chunk hash>.<ext>. Chunks are per post, so
 * deleting a post or replacing its audio never touches another post's
 * cache.
 *
 * Location: outside the web root where the install allows it; otherwise
 * under uploads with deny files; if those cannot be written the cache is
 * off (fail closed), so chunk audio is never left web-readable by surprise.
 */
final class ChunkCache
{
    public const DENY_FILES = [
        '.htaccess' => "# Written by read-aloud. Chunk audio is private working data.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        'index.html' => '',
    ];

    public function __construct(private readonly ?string $dir, private readonly bool $outsideWebRoot = false)
    {
    }

    /**
     * Decide where the cache lives. Returns a disabled cache when no safe
     * place exists.
     *
     * @param string|null $override Operator-chosen directory (constant or filter).
     * @param string|null $outsideCandidate A directory outside the web root, or null when none is known.
     */
    public static function locate(bool $enabled, ?string $override, ?string $outsideCandidate, string $uploadsDir): self
    {
        if (!$enabled) {
            return new self(null);
        }
        if ($override !== null && $override !== '') {
            $override = rtrim($override, '/');
            if (self::ensureDir($override)) {
                return new self($override, true);
            }
        }
        if ($outsideCandidate !== null && $outsideCandidate !== '') {
            $outsideCandidate = rtrim($outsideCandidate, '/');
            if (self::ensureDir($outsideCandidate)) {
                return new self($outsideCandidate, true);
            }
        }
        $inside = rtrim($uploadsDir, '/') . '/.chunks';
        if (!self::ensureDir($inside)) {
            return new self(null);
        }
        foreach (self::DENY_FILES as $name => $content) {
            $path = $inside . '/' . $name;
            // A deny file that exists but says something else (emptied,
            // edited, replaced by a directory) protects nothing: rewrite it,
            // or turn the cache off.
            if (is_file($path) && file_get_contents($path) === $content) {
                continue;
            }
            if ((file_exists($path) && !is_file($path)) || !is_writable($inside)) {
                return new self(null);
            }
            if (file_put_contents($path, $content) === false || file_get_contents($path) !== $content) {
                return new self(null);
            }
        }

        return new self($inside, false);
    }

    private static function ensureDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            $ancestor = dirname($dir);
            while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }
            if (!is_dir($ancestor) || !is_writable($ancestor)) {
                return false;
            }
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
        }

        return is_writable($dir);
    }

    public function enabled(): bool
    {
        return $this->dir !== null;
    }

    public function directory(): ?string
    {
        return $this->dir;
    }

    public function isOutsideWebRoot(): bool
    {
        return $this->outsideWebRoot;
    }

    private function path(int $postId, string $hash, string $extension): ?string
    {
        if ($this->dir === null || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || preg_match('/^[a-z0-9]{2,4}$/', $extension) !== 1) {
            return null;
        }

        return $this->dir . '/' . $postId . '/' . $hash . '.' . $extension;
    }

    public function has(int $postId, string $hash, string $extension): bool
    {
        $path = $this->path($postId, $hash, $extension);

        return $path !== null && is_file($path);
    }

    /** Path of a cached chunk, touched so pruning keeps it; null when absent. */
    public function get(int $postId, string $hash, string $extension): ?string
    {
        $path = $this->path($postId, $hash, $extension);
        if ($path === null || !is_file($path)) {
            return null;
        }
        touch($path);

        return $path;
    }

    /**
     * Move a synthesised chunk into the cache.
     *
     * @throws StorageException
     */
    public function put(int $postId, string $hash, string $extension, string $tmpPath): string
    {
        $path = $this->path($postId, $hash, $extension);
        if ($path === null) {
            throw new StorageException('The chunk cache is off.');
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new StorageException('The chunk cache directory cannot be created.');
        }
        // A server with directory listings on must not list a post's chunks.
        if (!is_file($directory . '/index.html') && file_put_contents($directory . '/index.html', '') === false) {
            throw new StorageException('The chunk cache directory cannot be protected.');
        }
        if (!rename($tmpPath, $path)) {
            if (!copy($tmpPath, $path)) {
                throw new StorageException('Writing a chunk to the cache failed.');
            }
            unlink($tmpPath);
        }

        return $path;
    }

    /**
     * Refresh the modification time of chunks so the retention clock starts now.
     *
     * @param list<string> $hashes
     */
    public function touch(int $postId, array $hashes, string $extension, int $time): void
    {
        foreach ($hashes as $hash) {
            $path = $this->path($postId, $hash, $extension);
            if ($path !== null && is_file($path)) {
                touch($path, $time);
            }
        }
    }

    /**
     * Delete chunks of a post that are neither kept nor younger than $olderThan.
     *
     * @param list<string> $keep Chunk hashes still referenced by the record.
     * @return int Files deleted.
     */
    public function prune(int $postId, array $keep, int $olderThan): int
    {
        if ($this->dir === null) {
            return 0;
        }
        $directory = $this->dir . '/' . $postId;
        if (!is_dir($directory)) {
            return 0;
        }
        $deleted = 0;
        $files = glob($directory . '/*.*');
        foreach ($files === false ? [] : $files as $file) {
            $hash = pathinfo($file, PATHINFO_FILENAME);
            if (basename($file) === 'index.html' || in_array($hash, $keep, true)) {
                continue;
            }
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $olderThan && unlink($file)) {
                $deleted++;
            }
        }
        $left = glob($directory . '/*');
        if ($left === [$directory . '/index.html']) {
            unlink($directory . '/index.html');
            $left = [];
        }
        if ($left === [] || $left === false) {
            rmdir($directory);
        }

        return $deleted;
    }

    public function deletePost(int $postId): int
    {
        if ($this->dir === null) {
            return 0;
        }
        $directory = $this->dir . '/' . $postId;
        if (!is_dir($directory)) {
            return 0;
        }
        $deleted = 0;
        $files = glob($directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            if (is_file($file) && unlink($file) && basename($file) !== 'index.html') {
                $deleted++;
            }
        }
        rmdir($directory);

        return $deleted;
    }

    /** @return list<int> Post ids that have a cache directory. */
    public function postIds(): array
    {
        if ($this->dir === null || !is_dir($this->dir)) {
            return [];
        }
        $out = [];
        $entries = scandir($this->dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if (ctype_digit($entry) && is_dir($this->dir . '/' . $entry)) {
                $out[] = (int) $entry;
            }
        }
        sort($out);

        return $out;
    }
}
