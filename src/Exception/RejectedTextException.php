<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * The provider refused the text itself. Not retried until the text changes.
 */
class RejectedTextException extends ReadAloudException
{
    public function errorCode(): string
    {
        return 'refused';
    }
}
