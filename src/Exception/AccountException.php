<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * Authentication or account failure (401, 403, exhausted credit).
 * Puts the whole site on hold.
 */
class AccountException extends ReadAloudException
{
    public function __construct(string $message, private readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return 'account';
    }
}
