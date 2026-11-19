<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests\Support;

/**
 * The stub wpdb over a real MySQL connection, for the ledger
 * integration test. Values are escaped by the connection.
 */
final class MysqliWpdb extends \wpdb
{
    public function __construct(private readonly \mysqli $link, string $prefix)
    {
        $this->prefix = $prefix;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%([isd])/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? null;
            if ($m[1] === 'd') {
                return (string) (int) $value;
            }
            if ($m[1] === 'i') {
                return '`' . str_replace('`', '``', (string) $value) . '`';
            }
            return "'" . $this->link->real_escape_string((string) $value) . "'";
        }, $query);
    }

    public function query(string $query): int|bool
    {
        $result = $this->link->query($query);
        $this->rows_affected = max(0, (int) $this->link->affected_rows);
        if ($result instanceof \mysqli_result) {
            $result->free();
        }

        return $result !== false;
    }

    public function get_row(?string $query = null, string $output = 'OBJECT'): mixed
    {
        $result = $this->link->query((string) $query);

        return $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
    }

    public function get_col(?string $query = null): array
    {
        $result = $this->link->query((string) $query);
        $out = [];
        if ($result instanceof \mysqli_result) {
            while (($row = $result->fetch_row()) !== null) {
                $out[] = $row[0];
            }
        }

        return $out;
    }

    public function get_results(?string $query = null, string $output = 'OBJECT'): mixed
    {
        $result = $this->link->query((string) $query);

        return $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
