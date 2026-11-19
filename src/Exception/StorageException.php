<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * Directory not writable, disk full, rename failed, or an attempt to change
 * the bytes behind an existing URL.
 */
class StorageException extends ReadAloudException
{
    public function errorCode(): string
    {
        return 'storage_error';
    }
}
