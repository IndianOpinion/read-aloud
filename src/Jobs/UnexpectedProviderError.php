<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Jobs;

use IndianOpinion\ReadAloud\Exception\ReadAloudException;

/**
 * Wraps a throwable an adapter should not have thrown. Treated as
 * transient once, then as permanent.
 */
final class UnexpectedProviderError extends ReadAloudException
{
    public function errorCode(): string
    {
        return 'provider_error';
    }
}
