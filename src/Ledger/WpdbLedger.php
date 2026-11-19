<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Ledger;

/**
 * Ledger on three small custom tables.
 *
 * Atomicity comes from single conditional UPDATE statements: the cap check
 * and the increment are one statement, so the database serialises
 * concurrent runners on the day row. This survives any persistent object
 * cache because the object cache is never consulted.
 *
 * Table names go through the %i identifier placeholder (WordPress 6.2+);
 * every value goes through a typed placeholder.
 */
final class WpdbLedger implements Ledger
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly \wpdb $db)
    {
    }

    private function days(): string
    {
        return $this->db->prefix . 'read_aloud_days';
    }

    private function claims(): string
    {
        return $this->db->prefix . 'read_aloud_claims';
    }

    private function locks(): string
    {
        return $this->db->prefix . 'read_aloud_locks';
    }

    /**
     * @param literal-string $sql
     */
    private function prepare(string $sql, mixed ...$args): string
    {
        return (string) $this->db->prepare($sql, ...$args);
    }

    /**
     * Run a prepared statement and return the affected row count.
     *
     * @param literal-string $sql
     */
    private function exec(string $sql, mixed ...$args): int
    {
        $this->db->query($this->prepare($sql, ...$args));

        return (int) $this->db->rows_affected;
    }

    public function install(): void
    {
        $collate = $this->db->get_charset_collate();
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->days() . ' (
            day CHAR(10) NOT NULL,
            chars BIGINT NOT NULL DEFAULT 0,
            generations INT NOT NULL DEFAULT 0,
            money BIGINT NOT NULL DEFAULT 0,
            reserved_chars BIGINT NOT NULL DEFAULT 0,
            reserved_generations INT NOT NULL DEFAULT 0,
            reserved_money BIGINT NOT NULL DEFAULT 0,
            saves_skipped INT NOT NULL DEFAULT 0,
            flags INT NOT NULL DEFAULT 0,
            PRIMARY KEY (day)
        ) ' . $collate);
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->claims() . ' (
            id CHAR(32) NOT NULL,
            day CHAR(10) NOT NULL,
            post_id BIGINT NOT NULL DEFAULT 0,
            chars BIGINT NOT NULL DEFAULT 0,
            generations INT NOT NULL DEFAULT 0,
            money BIGINT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ' . $collate);
        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $this->locks() . ' (
            name VARCHAR(64) NOT NULL,
            owner CHAR(32) NOT NULL,
            expires_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (name)
        ) ' . $collate);
    }

    public function uninstall(): void
    {
        foreach ([$this->days(), $this->claims(), $this->locks()] as $table) {
            $this->exec('DROP TABLE IF EXISTS %i', $table);
        }
    }

    private function ensureDay(string $day): void
    {
        $this->exec('INSERT IGNORE INTO %i (day) VALUES (%s)', $this->days(), $day);
    }

    public function reserve(string $day, int $chars, int $moneyMicros, Caps $caps, bool $bypassCaps, int $now, int $postId): ?string
    {
        $chars = max(0, $chars);
        $moneyMicros = max(0, $moneyMicros);
        $this->ensureDay($day);
        $set = 'UPDATE %i SET reserved_chars = reserved_chars + %d, reserved_generations = reserved_generations + 1,'
            . ' reserved_money = reserved_money + %d WHERE day = %s';
        if ($bypassCaps) {
            $affected = $this->exec($set, $this->days(), $chars, $moneyMicros, $day);
        } elseif ($caps->moneyMicros === null) {
            $affected = $this->exec(
                $set . ' AND chars + reserved_chars + %d <= %d AND generations + reserved_generations + 1 <= %d',
                $this->days(),
                $chars,
                $moneyMicros,
                $day,
                $chars,
                $caps->chars,
                $caps->generations
            );
        } else {
            $affected = $this->exec(
                $set . ' AND chars + reserved_chars + %d <= %d AND generations + reserved_generations + 1 <= %d'
                . ' AND money + reserved_money + %d <= %d',
                $this->days(),
                $chars,
                $moneyMicros,
                $day,
                $chars,
                $caps->chars,
                $caps->generations,
                $moneyMicros,
                $caps->moneyMicros
            );
        }
        if ($affected !== 1) {
            return null;
        }
        $id = bin2hex(random_bytes(16));
        $this->exec(
            'INSERT INTO %i (id, day, post_id, chars, generations, money, created_at) VALUES (%s, %s, %d, %d, 1, %d, %d)',
            $this->claims(),
            $id,
            $day,
            $postId,
            $chars,
            $moneyMicros,
            $now
        );

        return $id;
    }

    /**
     * @return array{day: string, chars: int, generations: int, money: int}|null
     */
    private function claim(string $id): ?array
    {
        $row = $this->db->get_row($this->prepare('SELECT day, chars, generations, money FROM %i WHERE id = %s', $this->claims(), $id), 'ARRAY_A');
        if (!is_array($row)) {
            return null;
        }

        return [
            'day' => (string) $row['day'],
            'chars' => (int) $row['chars'],
            'generations' => (int) $row['generations'],
            'money' => (int) $row['money'],
        ];
    }

    public function settle(string $reservationId, int $reservedChars, int $billedChars, int $reservedMoney, int $billedMoney, ?int $now = null): void
    {
        $claim = $this->claim($reservationId);
        if ($claim === null) {
            return;
        }
        $releaseChars = min($claim['chars'], max(0, $reservedChars));
        $releaseMoney = min($claim['money'], max(0, $reservedMoney));
        // Conditional on the claim still holding what is released, so a
        // concurrent release (stale sweep) and this settle never both take
        // the same share off the day row.
        $settled = $this->exec(
            'UPDATE %i SET chars = chars - %d, money = money - %d, created_at = GREATEST(created_at, %d)'
            . ' WHERE id = %s AND chars >= %d AND money >= %d',
            $this->claims(),
            $releaseChars,
            $releaseMoney,
            $now ?? 0,
            $reservationId,
            $releaseChars,
            $releaseMoney
        );
        if ($settled !== 1) {
            // The claim went away meanwhile: its reservation is already
            // released, but the call happened, so count what was billed.
            $releaseChars = 0;
            $releaseMoney = 0;
        }
        $this->exec(
            'UPDATE %i SET reserved_chars = reserved_chars - %d, reserved_money = reserved_money - %d,'
            . ' chars = chars + %d, money = money + %d WHERE day = %s',
            $this->days(),
            $releaseChars,
            $releaseMoney,
            max(0, $billedChars),
            max(0, $billedMoney),
            $claim['day']
        );
    }

    public function complete(string $reservationId): void
    {
        $claim = $this->claim($reservationId);
        if ($claim === null) {
            return;
        }
        if ($this->exec('DELETE FROM %i WHERE id = %s', $this->claims(), $reservationId) !== 1) {
            return;
        }
        $this->exec(
            'UPDATE %i SET reserved_chars = reserved_chars - %d, reserved_money = reserved_money - %d,'
            . ' reserved_generations = reserved_generations - %d, generations = generations + %d WHERE day = %s',
            $this->days(),
            $claim['chars'],
            $claim['money'],
            $claim['generations'],
            $claim['generations'],
            $claim['day']
        );
    }

    public function release(string $reservationId): void
    {
        $claim = $this->claim($reservationId);
        if ($claim === null) {
            return;
        }
        if ($this->exec('DELETE FROM %i WHERE id = %s', $this->claims(), $reservationId) !== 1) {
            return;
        }
        $this->exec(
            'UPDATE %i SET reserved_chars = reserved_chars - %d, reserved_money = reserved_money - %d,'
            . ' reserved_generations = reserved_generations - %d WHERE day = %s',
            $this->days(),
            $claim['chars'],
            $claim['money'],
            $claim['generations'],
            $claim['day']
        );
    }

    public function releaseStale(int $olderThan): int
    {
        $ids = $this->db->get_col($this->prepare('SELECT id FROM %i WHERE created_at < %d', $this->claims(), $olderThan));
        $count = 0;
        foreach ($ids as $id) {
            $this->release((string) $id);
            $count++;
        }

        return $count;
    }

    public function usage(string $day): array
    {
        $row = $this->db->get_row($this->prepare(
            'SELECT day, chars, generations, money, reserved_chars, reserved_generations, reserved_money, saves_skipped, flags FROM %i WHERE day = %s',
            $this->days(),
            $day
        ), 'ARRAY_A');

        return self::row(is_array($row) ? $row : ['day' => $day]);
    }

    public function history(int $days, string $today): array
    {
        $rows = $this->db->get_results($this->prepare(
            'SELECT day, chars, generations, money, reserved_chars, reserved_generations, reserved_money, saves_skipped, flags FROM %i'
            . ' WHERE day <= %s ORDER BY day DESC LIMIT %d',
            $this->days(),
            $today,
            max(1, $days)
        ), 'ARRAY_A');
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = self::row($row);
            }
        }

        return $out;
    }

    public function incrementSavesSkipped(string $day): void
    {
        $this->ensureDay($day);
        $this->exec('UPDATE %i SET saves_skipped = saves_skipped + 1 WHERE day = %s', $this->days(), $day);
    }

    public function addUsage(string $day, int $chars, int $moneyMicros): void
    {
        $this->ensureDay($day);
        $this->exec(
            'UPDATE %i SET chars = chars + %d, money = money + %d, generations = generations + 1 WHERE day = %s',
            $this->days(),
            max(0, $chars),
            max(0, $moneyMicros),
            $day
        );
    }

    public function setFlag(string $day, int $flag): bool
    {
        $this->ensureDay($day);

        return $this->exec('UPDATE %i SET flags = flags | %d WHERE day = %s AND (flags & %d) = 0', $this->days(), $flag, $day, $flag) === 1;
    }

    public function trim(string $oldestDay): int
    {
        return $this->exec('DELETE FROM %i WHERE day < %s', $this->days(), $oldestDay);
    }

    public function lock(string $name, string $owner, int $now, int $ttl): string
    {
        if ($this->exec('INSERT IGNORE INTO %i (name, owner, expires_at) VALUES (%s, %s, %d)', $this->locks(), $name, $owner, $now + $ttl) === 1) {
            return 'acquired';
        }
        $taken = $this->exec(
            'UPDATE %i SET owner = %s, expires_at = %d WHERE name = %s AND expires_at < %d',
            $this->locks(),
            $owner,
            $now + $ttl,
            $name,
            $now
        );

        return $taken === 1 ? 'taken_over' : 'busy';
    }

    public function unlock(string $name, string $owner): void
    {
        $this->exec('DELETE FROM %i WHERE name = %s AND owner = %s', $this->locks(), $name, $owner);
    }

    public function renew(string $name, string $owner, int $now, int $ttl): bool
    {
        $this->exec('UPDATE %i SET expires_at = %d WHERE name = %s AND owner = %s', $this->locks(), $now + $ttl, $name, $owner);

        // rows_affected is 0 when the value did not change, so read it back.
        $held = $this->db->get_col($this->prepare('SELECT owner FROM %i WHERE name = %s', $this->locks(), $name));

        return ($held[0] ?? null) === $owner;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{day: string, chars: int, generations: int, money: int, reserved_chars: int, reserved_generations: int, reserved_money: int, saves_skipped: int, flags: int}
     */
    private static function row(array $row): array
    {
        $int = static fn (string $key): int => isset($row[$key]) && is_numeric($row[$key]) ? (int) $row[$key] : 0;

        return [
            'day' => isset($row['day']) && is_scalar($row['day']) ? (string) $row['day'] : '',
            'chars' => $int('chars'),
            'generations' => $int('generations'),
            'money' => $int('money'),
            'reserved_chars' => $int('reserved_chars'),
            'reserved_generations' => $int('reserved_generations'),
            'reserved_money' => $int('reserved_money'),
            'saves_skipped' => $int('saves_skipped'),
            'flags' => $int('flags'),
        ];
    }
}
