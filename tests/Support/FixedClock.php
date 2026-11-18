<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests\Support;

use IndianOpinion\ReadAloud\Clock\Clock;

final class FixedClock implements Clock
{
    public function __construct(public int $time = 1793527200)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
