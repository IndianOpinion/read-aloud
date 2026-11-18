<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Clock;

/**
 * Source of the current time. Injected so tests can move time.
 */
interface Clock
{
    /** Unix timestamp in seconds. */
    public function now(): int;
}
