<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Storage;

use IndianOpinion\ReadAloud\Exception\StorageException;

/**
 * Where finished audio files live. The default writes under uploads; an
 * object-storage implementation can be supplied through read_aloud_store.
 *
 * Keys look like "2026/11/123-0123456789ab.mp3". A key, once written, must
 * never be given different bytes.
 */
interface AudioStore
{
    /**
     * Move a finished temporary file into place under $key.
     *
     * @throws StorageException
     */
    public function put(string $tmpPath, string $key, string $mimeType): StoredFile;

    public function url(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): bool;

    public function size(string $key): ?int;
}
