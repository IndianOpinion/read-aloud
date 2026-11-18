<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Storage;

final class StoredFile
{
    public function __construct(
        public readonly string $key,
        public readonly string $url,
        public readonly int $bytes
    ) {
    }
}
