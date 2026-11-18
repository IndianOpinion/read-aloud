<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * HTTP 429 or an equivalent provider signal.
 */
class RateLimitedException extends TransientProviderException
{
    public function errorCode(): string
    {
        return 'rate_limited';
    }
}
