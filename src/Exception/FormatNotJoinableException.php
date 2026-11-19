<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * The text needs more than one chunk and the format cannot be joined.
 * Raised before any provider call.
 */
class FormatNotJoinableException extends ReadAloudException
{
    public function errorCode(): string
    {
        return 'format_not_joinable';
    }
}
