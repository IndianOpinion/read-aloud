<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * Base class for every error this plugin throws on purpose.
 *
 * Each subclass carries a stable machine code that is stored in the audio
 * record and shown on the status screen.
 */
class ReadAloudException extends \RuntimeException
{
    public function errorCode(): string
    {
        return 'error';
    }
}
