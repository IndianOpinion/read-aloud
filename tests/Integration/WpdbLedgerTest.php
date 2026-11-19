<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests\Integration;

use IndianOpinion\ReadAloud\Ledger\Caps;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Ledger\WpdbLedger;
use IndianOpinion\ReadAloud\Tests\Support\MysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The SQL ledger against a real MySQL server (ruling 1: a small custom
 * table with an atomic update). Skipped unless READ_ALOUD_TEST_MYSQL holds
 * "host:port:user:password:database" or a socket path in place of host:port
 * ("socket=/path:user:password:database").
 */
final class WpdbLedgerTest extends TestCase
{
    /** @var list<\mysqli> */
    private array $links = [];
    private string $prefix;

    private function connect(): \mysqli
    {
        $dsn = getenv('READ_ALOUD_TEST_MYSQL');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('Set READ_ALOUD_TEST_MYSQL to run the MySQL ledger tests.');
        }
        $parts = explode(':', $dsn);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        if (str_starts_with($parts[0], 'socket=')) {
            [$socket, $user, $password, $database] = [substr($parts[0], 7), $parts[1], $parts[2], $parts[3]];
            $link = new \mysqli('localhost', $user, $password, $database, 0, $socket);
        } else {
            [$host, $port, $user, $password, $database] = $parts;
            $link = new \mysqli($host, $user, $password, $database, (int) $port);
        }
        $this->links[] = $link;

        return $link;
    }

    private function ledger(?\mysqli $link = null): WpdbLedger
    {
        return new WpdbLedger(new MysqliWpdb($link ?? $this->connect(), $this->prefix));
    }

    protected function setUp(): void
    {
        $this->prefix = 'ra' . bin2hex(random_bytes(3)) . '_';
    }

    protected function tearDown(): void
    {
        if ($this->links !== []) {
            $this->ledger($this->links[0])->uninstall();
        }
        foreach ($this->links as $link) {
            $link->close();
        }
    }

    public function testConcurrentReservationsFromTwoConnections(): void
    {
        $first = $this->ledger();
        $first->install();
        $second = $this->ledger($this->connect());
        $caps = new Caps(1000, 20);
        $this->assertNotNull($first->reserve('2026-11-15', 600, 0, $caps, false, 1000, 1));
        $this->assertNull($second->reserve('2026-11-15', 600, 0, $caps, false, 1000, 2), 'two runners cannot both pass a nearly full cap');
        $this->assertNotNull($second->reserve('2026-11-15', 400, 0, $caps, false, 1000, 2));
        $this->assertSame(1000, $first->usage('2026-11-15')['reserved_chars']);
    }

    public function testSettleCompleteReleaseAndStale(): void
    {
        $ledger = $this->ledger();
        $ledger->install();
        $caps = new Caps(10000, 5, 1000);
        $id = (string) $ledger->reserve('2026-11-15', 900, 300, $caps, false, 1000, 1);
        $ledger->settle($id, 500, 480, 100, 90);
        $usage = $ledger->usage('2026-11-15');
        $this->assertSame([480, 400, 90, 200], [$usage['chars'], $usage['reserved_chars'], $usage['money'], $usage['reserved_money']]);
        $ledger->complete($id);
        $usage = $ledger->usage('2026-11-15');
        $this->assertSame([0, 0, 1, 0], [$usage['reserved_chars'], $usage['reserved_money'], $usage['generations'], $usage['reserved_generations']]);

        $this->assertNull($ledger->reserve('2026-11-15', 10, 911, $caps, false, 1000, 1), 'money cap');
        $stale = (string) $ledger->reserve('2026-11-15', 100, 0, $caps, false, 1000, 2);
        $ledger->release('missing');
        $this->assertSame(1, $ledger->releaseStale(5000));
        $this->assertSame(0, $ledger->usage('2026-11-15')['reserved_chars']);
        $ledger->release($stale);
        $this->assertSame(0, $ledger->usage('2026-11-15')['reserved_generations']);
        $this->assertNotNull($ledger->reserve('2026-11-15', 999999, 999999, $caps, true, 1000, 3), 'bypass');
    }

    /** Review fix: a settle refreshes the claim, and a settle that races a release never double-counts. */
    public function testSettleRefreshesClaimAndSurvivesARelease(): void
    {
        $ledger = $this->ledger();
        $ledger->install();
        $caps = new Caps(10000, 5);
        $id = (string) $ledger->reserve('2026-11-15', 900, 0, $caps, false, 1000, 1);
        $ledger->settle($id, 300, 300, 0, 0, 9000);
        $this->assertSame(0, $ledger->releaseStale(5000), 'settled at 9000: not stale at 5000');

        $ledger->release($id);
        $ledger->settle($id, 300, 300, 0, 0, 9100);
        $usage = $ledger->usage('2026-11-15');
        $this->assertSame(0, $usage['reserved_chars'], 'never below zero');
        $this->assertSame(300, $usage['chars']);
    }

    public function testRenewOnlyByOwner(): void
    {
        $ledger = $this->ledger();
        $ledger->install();
        $this->assertSame('acquired', $ledger->lock('post-9', str_repeat('a', 32), 1000, 900));
        $this->assertTrue($ledger->renew('post-9', str_repeat('a', 32), 1800, 900));
        $this->assertTrue($ledger->renew('post-9', str_repeat('a', 32), 1800, 900), 'unchanged expiry still counts as held');
        $this->assertSame('busy', $ledger->lock('post-9', str_repeat('b', 32), 2000, 900), 'renewed until 2700');
        $this->assertFalse($ledger->renew('post-9', str_repeat('b', 32), 2000, 900));
    }

    public function testFlagsCountersTrimAndLocks(): void
    {
        $ledger = $this->ledger();
        $ledger->install();
        $this->assertTrue($ledger->setFlag('2026-11-15', Ledger::FLAG_WARN_CHARS));
        $this->assertFalse($ledger->setFlag('2026-11-15', Ledger::FLAG_WARN_CHARS));
        $this->assertTrue($ledger->setFlag('2026-11-15', Ledger::FLAG_REACHED_CHARS));
        $ledger->incrementSavesSkipped('2026-11-14');
        $ledger->addUsage('2026-11-13', 100, 5);
        $this->assertCount(3, $ledger->history(30, '2026-11-15'));
        $this->assertSame(1, $ledger->trim('2026-11-14'));
        $this->assertSame(['2026-11-15', '2026-11-14'], array_column($ledger->history(30, '2026-11-15'), 'day'));

        $this->assertSame('acquired', $ledger->lock('post-1', str_repeat('a', 32), 1000, 900));
        $this->assertSame('busy', $ledger->lock('post-1', str_repeat('b', 32), 1500, 900));
        $this->assertSame('taken_over', $ledger->lock('post-1', str_repeat('b', 32), 2000, 900));
        $ledger->unlock('post-1', str_repeat('a', 32));
        $this->assertSame('busy', $ledger->lock('post-1', str_repeat('c', 32), 2001, 900), 'only the owner unlocks');
        $ledger->unlock('post-1', str_repeat('b', 32));
        $this->assertSame('acquired', $ledger->lock('post-1', str_repeat('c', 32), 2002, 900));
    }
}
