<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests\Support;

use IndianOpinion\ReadAloud\Storage\AudioStore;
use IndianOpinion\ReadAloud\Storage\StoredFile;

/**
 * Object-storage style store: keeps bytes in memory, counts calls.
 */
final class FakeStore implements AudioStore
{
    /** @var array<string, string> */
    public array $files = [];
    /** @var list<string> */
    public array $calls = [];

    public function put(string $tmpPath, string $key, string $mimeType): StoredFile
    {
        $this->calls[] = 'put:' . $key;
        $this->files[$key] = (string) file_get_contents($tmpPath);

        return new StoredFile($key, $this->url($key), strlen($this->files[$key]));
    }

    public function url(string $key): string
    {
        return 'https://cdn.example.test/audio/' . $key;
    }

    public function exists(string $key): bool
    {
        $this->calls[] = 'exists:' . $key;

        return isset($this->files[$key]);
    }

    public function delete(string $key): bool
    {
        $this->calls[] = 'delete:' . $key;
        $had = isset($this->files[$key]);
        unset($this->files[$key]);

        return $had;
    }

    public function size(string $key): ?int
    {
        return isset($this->files[$key]) ? strlen($this->files[$key]) : null;
    }
}
