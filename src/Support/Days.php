<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Support;

use IndianOpinion\ReadAloud\Clock\Clock;

/**
 * Cap days follow the site's time zone (ruling on open question 8).
 */
final class Days
{
    public function __construct(private readonly Clock $clock, private readonly ?\DateTimeZone $zone = null)
    {
    }

    private function zone(): \DateTimeZone
    {
        if ($this->zone !== null) {
            return $this->zone;
        }

        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }

    public function today(): string
    {
        return $this->dayOf($this->clock->now());
    }

    public function dayOf(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone())->format('Y-m-d');
    }

    /** 00:05 of the next local day, as a timestamp. */
    public function nextDayStart(?int $from = null): int
    {
        $from ??= $this->clock->now();
        $local = (new \DateTimeImmutable('@' . $from))->setTimezone($this->zone());

        return $local->setTime(0, 5)->modify('+1 day')->getTimestamp();
    }

    /** The day $n days before today. */
    public function daysAgo(int $n): string
    {
        $local = (new \DateTimeImmutable('@' . $this->clock->now()))->setTimezone($this->zone());

        return $local->modify('-' . $n . ' days')->format('Y-m-d');
    }

    public function now(): int
    {
        return $this->clock->now();
    }
}
