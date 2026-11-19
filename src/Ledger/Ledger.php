<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Ledger;

/**
 * Daily counters, atomic reservations and run locks.
 *
 * Every method reads and writes the database directly; nothing is trusted
 * from a persistent object cache.
 */
interface Ledger
{
    public const FLAG_WARN_CHARS = 1;
    public const FLAG_WARN_GENERATIONS = 2;
    public const FLAG_WARN_MONEY = 4;
    public const FLAG_REACHED_CHARS = 8;
    public const FLAG_REACHED_GENERATIONS = 16;
    public const FLAG_REACHED_MONEY = 32;
    public const FLAG_LOG_SPEND_METER = 64;
    public const FLAG_LOG_GEN_QUEUE = 128;

    /**
     * Reserve characters, one generation and money for a job. Atomic: two
     * runners cannot both pass a nearly full cap.
     *
     * @return string|null Reservation id, or null when a cap would be exceeded.
     */
    public function reserve(string $day, int $chars, int $moneyMicros, Caps $caps, bool $bypassCaps, int $now, int $postId): ?string;

    /**
     * Move part of a reservation into used counters after one call.
     * $reservedChars is what this call had reserved; $billedChars what the
     * provider billed (0 for an unbilled failure, which releases the share).
     * When $now is given the reservation counts as settled at that time, so
     * the stale-reservation sweep never releases a job that is still
     * settling chunks.
     */
    public function settle(string $reservationId, int $reservedChars, int $billedChars, int $reservedMoney, int $billedMoney, ?int $now = null): void;

    /** Count the reserved generation as completed and release whatever is left. */
    public function complete(string $reservationId): void;

    /** Release everything a reservation still holds. */
    public function release(string $reservationId): void;

    /**
     * Release reservations created before $olderThan.
     *
     * @return int Number released.
     */
    public function releaseStale(int $olderThan): int;

    /**
     * @return array{day: string, chars: int, generations: int, money: int, reserved_chars: int, reserved_generations: int, reserved_money: int, saves_skipped: int, flags: int}
     */
    public function usage(string $day): array;

    /**
     * @return list<array{day: string, chars: int, generations: int, money: int, reserved_chars: int, reserved_generations: int, reserved_money: int, saves_skipped: int, flags: int}>
     */
    public function history(int $days, string $today): array;

    public function incrementSavesSkipped(string $day): void;

    /** Count a call that bypassed reservations (the settings test voice). */
    public function addUsage(string $day, int $chars, int $moneyMicros): void;

    /** Set a flag bit for a day. True only for the caller that set it first. */
    public function setFlag(string $day, int $flag): bool;

    /** Delete days before $oldestDay. */
    public function trim(string $oldestDay): int;

    /**
     * Take a named lock. Returns "acquired", "taken_over" (an expired lock
     * was replaced) or "busy".
     */
    public function lock(string $name, string $owner, int $now, int $ttl): string;

    public function unlock(string $name, string $owner): void;

    /**
     * Push the expiry of a lock this owner holds. False when the lock is no
     * longer this owner's (it expired and another runner took it over).
     */
    public function renew(string $name, string $owner, int $now, int $ttl): bool;

    /** Create the tables if needed. */
    public function install(): void;

    /** Drop the tables (uninstall). */
    public function uninstall(): void;
}
