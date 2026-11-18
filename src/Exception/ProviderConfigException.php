<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * The provider is not configured correctly (missing endpoint, bad template,
 * refused address). Permanent until settings change.
 */
class ProviderConfigException extends ReadAloudException
{
    public function errorCode(): string
    {
        return 'provider_config';
    }
}
