<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests\Support;

use IndianOpinion\ReadAloud\Ledger\Caps;
use IndianOpinion\ReadAloud\Ledger\Ledger;

/**
 * In-memory ledger with the same semantics as the SQL one. PHP runs one
 * request at a time, so every method is atomic here; the SQL ledger is
 * covered against MySQL in tests/Integration.
 */
final class MemoryLedger implements Ledger
{
    /** @var array<string, array{day: string, chars: int, generations: int, money: int, reserved_chars: int, reserved_generations: int, reserved_money: int, saves_skipped: int, flags: int}> */
    public array $days = [];
    /** @var array<string, array{day: string, chars: int, generations: int, money: int, created_at: int, post_id: int}> */
    public array $claims = [];
    /** @var array<string, array{owner: string, expires_at: int}> */
    public array $locks = [];
    public bool $installed = false;
    public bool $uninstalled = false;

    private function &day(string $day): array
    {
        if (!isset($this->days[$day])) {
            $this->days[$day] = ['day' => $day, 'chars' => 0, 'generations' => 0, 'money' => 0, 'reserved_chars' => 0, 'reserved_generations' => 0, 'reserved_money' => 0, 'saves_skipped' => 0, 'flags' => 0];
        }

        return $this->days[$day];
    }

    public function reserve(string $day, int $chars, int $moneyMicros, Caps $caps, bool $bypassCaps, int $now, int $postId): ?string
    {
        $row = &$this->day($day);
        if (!$bypassCaps) {
            if ($row['chars'] + $row['reserved_chars'] + $chars > $caps->chars) {
                return null;
            }
            if ($row['generations'] + $row['reserved_generations'] + 1 > $caps->generations) {
                return null;
            }
            if ($caps->moneyMicros !== null && $row['money'] + $row['reserved_money'] + $moneyMicros > $caps->moneyMicros) {
                return null;
            }
        }
        $row['reserved_chars'] += $chars;
        $row['reserved_generations'] += 1;
        $row['reserved_money'] += $moneyMicros;
        $id = bin2hex(random_bytes(16));
        $this->claims[$id] = ['day' => $day, 'chars' => $chars, 'generations' => 1, 'money' => $moneyMicros, 'created_at' => $now, 'post_id' => $postId];

        return $id;
    }

    public function settle(string $reservationId, int $reservedChars, int $billedChars, int $reservedMoney, int $billedMoney): void
    {
        if (!isset($this->claims[$reservationId])) {
            return;
        }
        $claim = &$this->claims[$reservationId];
        $releaseChars = min($claim['chars'], max(0, $reservedChars));
        $releaseMoney = min($claim['money'], max(0, $reservedMoney));
        $claim['chars'] -= $releaseChars;
        $claim['money'] -= $releaseMoney;
        $row = &$this->day($claim['day']);
        $row['reserved_chars'] -= $releaseChars;
        $row['reserved_money'] -= $releaseMoney;
        $row['chars'] += max(0, $billedChars);
        $row['money'] += max(0, $billedMoney);
    }

    public function complete(string $reservationId): void
    {
        if (!isset($this->claims[$reservationId])) {
            return;
        }
        $claim = $this->claims[$reservationId];
        unset($this->claims[$reservationId]);
        $row = &$this->day($claim['day']);
        $row['reserved_chars'] -= $claim['chars'];
        $row['reserved_money'] -= $claim['money'];
        $row['reserved_generations'] -= $claim['generations'];
        $row['generations'] += $claim['generations'];
    }

    public function release(string $reservationId): void
    {
        if (!isset($this->claims[$reservationId])) {
            return;
        }
        $claim = $this->claims[$reservationId];
        unset($this->claims[$reservationId]);
        $row = &$this->day($claim['day']);
        $row['reserved_chars'] -= $claim['chars'];
        $row['reserved_money'] -= $claim['money'];
        $row['reserved_generations'] -= $claim['generations'];
    }

    public function releaseStale(int $olderThan): int
    {
        $count = 0;
        foreach ($this->claims as $id => $claim) {
            if ($claim['created_at'] < $olderThan) {
                $this->release($id);
                $count++;
            }
        }

        return $count;
    }

    public function usage(string $day): array
    {
        return $this->days[$day] ?? ['day' => $day, 'chars' => 0, 'generations' => 0, 'money' => 0, 'reserved_chars' => 0, 'reserved_generations' => 0, 'reserved_money' => 0, 'saves_skipped' => 0, 'flags' => 0];
    }

    public function history(int $days, string $today): array
    {
        $rows = array_filter($this->days, static fn (array $row): bool => $row['day'] <= $today);
        krsort($rows);

        return array_values(array_slice($rows, 0, $days));
    }

    public function incrementSavesSkipped(string $day): void
    {
        $row = &$this->day($day);
        $row['saves_skipped']++;
    }

    public function addUsage(string $day, int $chars, int $moneyMicros): void
    {
        $row = &$this->day($day);
        $row['chars'] += $chars;
        $row['money'] += $moneyMicros;
        $row['generations']++;
    }

    public function setFlag(string $day, int $flag): bool
    {
        $row = &$this->day($day);
        if (($row['flags'] & $flag) !== 0) {
            return false;
        }
        $row['flags'] |= $flag;

        return true;
    }

    public function trim(string $oldestDay): int
    {
        $count = 0;
        foreach (array_keys($this->days) as $day) {
            if ($day < $oldestDay) {
                unset($this->days[$day]);
                $count++;
            }
        }

        return $count;
    }

    public function lock(string $name, string $owner, int $now, int $ttl): string
    {
        if (!isset($this->locks[$name])) {
            $this->locks[$name] = ['owner' => $owner, 'expires_at' => $now + $ttl];
            return 'acquired';
        }
        if ($this->locks[$name]['expires_at'] < $now) {
            $this->locks[$name] = ['owner' => $owner, 'expires_at' => $now + $ttl];
            return 'taken_over';
        }

        return 'busy';
    }

    public function unlock(string $name, string $owner): void
    {
        if (isset($this->locks[$name]) && $this->locks[$name]['owner'] === $owner) {
            unset($this->locks[$name]);
        }
    }

    public function install(): void
    {
        $this->installed = true;
    }

    public function uninstall(): void
    {
        $this->uninstalled = true;
        $this->days = [];
        $this->claims = [];
        $this->locks = [];
    }
}
