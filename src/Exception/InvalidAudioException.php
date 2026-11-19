<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Exception;

/**
 * A response that is not the audio we asked for: HTML, JSON error text,
 * a body that is too short, or the wrong magic bytes.
 */
class InvalidAudioException extends ReadAloudException
{
    public function errorCode(): string
    {
        return 'invalid_audio';
    }
}
