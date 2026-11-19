<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * A failure that may succeed later: timeout, connection error, 5xx.
 */
class TransientProviderException extends ReadAloudException
{
    public function __construct(
        string $message,
        private readonly ?int $retryAfter = null,
        private readonly int $httpStatus = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Seconds the provider asked us to wait, or null. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return 'transient';
    }
}
