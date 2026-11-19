<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Ledger\Caps;

/**
 * PRD 12.7, cases 58 to 65.
 */
final class CapsTest extends TestCase
{
    /** Test 51 (case 58). */
    public function testFilterCanOnlyTurnOff(): void
    {
        $guard = $this->plugin->guard();
        $this->assertTrue($guard->enabled());
        add_filter('read_aloud_enabled', fn (): bool => false);
        $this->assertFalse($guard->enabled());
        remove_all_filters('read_aloud_enabled');
        $guard->setEnabled(false, 'test');
        add_filter('read_aloud_enabled', fn (): bool => true);
        $this->assertFalse($guard->enabled(), 'the filter cannot turn generation on');
        $this->assertContains('options:read_aloud_enabled', \WpStub::$cacheDeleted, 'read fresh, not from the object cache');
    }

    /** Test 51 (case 58): the constant, in a separate process. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testConstantOverridesSetting(): void
    {
        define('READ_ALOUD_DISABLED', true);
        $guard = $this->plugin->guard();
        $guard->setEnabled(true, 'test');
        $this->assertTrue($guard->constantDisabled());
        $this->assertFalse($guard->enabled());
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('stopped_kill', $this->run_($post->ID));
        $html = (new \IndianOpinion\ReadAloud\Admin\SettingsPage($this->plugin))->html();
        $this->assertStringContainsString('READ_ALOUD_DISABLED is defined', $html);
    }

    /** Test 52 (case 59). */
    public function testKillSwitchOffKeepsQueueAndFiles(): void
    {
        $approved = $this->approvedPost();
        $post = $this->post(['post_title' => 'Queued']);
        $this->trigger($post->ID);
        $calls = count($this->provider->requests);
        $this->plugin->guard()->setEnabled(false, 'test');
        $this->clock->advance(700);
        $this->assertSame(['stopped_kill' => 1], $this->plugin->runner()->runDue());
        $this->assertSame('stopped_kill', $this->run_($post->ID));
        $this->assertSame($calls, count($this->provider->requests));
        $this->assertSame(Status::QUEUED, $this->record($post->ID)->status);
        $this->assertNotSame('', read_aloud_player($approved->ID));
        $this->settings(['show_player' => false]);
        $this->assertSame('', read_aloud_player($approved->ID));
    }

    /** Test 53 (case 60). */
    public function testZeroCapAndMoneyCap(): void
    {
        $this->settings(['cap_chars' => 0]);
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('skipped', $this->run_($post->ID));
        $this->assertSame([], $this->provider->requests);

        $this->settings(['cap_chars' => 50000, 'cap_money' => '0.001']);
        $this->assertNull($this->plugin->capPolicy()->caps()->moneyMicros, 'no money cap without a price');
        $this->settings(['price_per_million' => '15']);
        $this->assertSame(1000, $this->plugin->capPolicy()->caps()->moneyMicros);
        $other = $this->post(['post_title' => 'Money']);
        $this->trigger($other->ID, 'generate');
        $this->assertSame('capped', $this->run_($other->ID));
        $this->assertSame([], $this->provider->requests);
        $this->settings(['cap_money' => '1']);
        $this->clock->advance(86400);
        $this->assertSame('done', $this->run_($other->ID));
        $this->assertGreaterThan(0, $this->today()['money']);
    }

    /** Test 54 (case 61). */
    public function testConcurrentReservationsAndNextDay(): void
    {
        $caps = new Caps(1000, 20, null);
        $day = $this->plugin->days()->today();
        $first = $this->ledger->reserve($day, 600, 0, $caps, false, $this->clock->now(), 1);
        $second = $this->ledger->reserve($day, 600, 0, $caps, false, $this->clock->now(), 2);
        $this->assertNotNull($first);
        $this->assertNull($second);

        update_option('timezone_string', 'Asia/Kolkata');
        $this->settings(['cap_chars' => 700]);
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('capped', $this->run_($post->ID));
        $record = $this->record($post->ID);
        $this->assertSame(Status::CAPPED, $record->status);
        $expected = (new \DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->setTime(0, 5)->modify('+1 day')->getTimestamp();
        $this->assertSame($expected, $record->nextAttemptAt);
        $this->assertSame($expected, \WpStub::cronFor('read_aloud_job')[0][0]);
    }

    /** Test 55 (cases 61, 43). */
    public function testReservationCountsOnlyMissingChunks(): void
    {
        $paragraphs = [self::words(850, 'a'), self::words(850, 'b'), self::words(850, 'c')];
        $post = $this->post(['post_title' => 'T', 'post_content' => self::blocks($paragraphs)]);
        $this->trigger($post->ID, 'generate');
        $this->run_($post->ID);
        $this->plugin->review()->approve($post->ID, self::EDITOR);
        $charsBefore = $this->today()['chars'];

        $paragraphs[1] = str_replace('council', 'board', $paragraphs[1]);
        $this->edit($post, self::blocks($paragraphs));
        $this->trigger($post->ID, 'update');
        $reserved = [];
        $this->provider->onCall = function () use (&$reserved): void {
            $reserved[] = $this->today()['reserved_chars'];
        };
        $this->run_($post->ID);
        $this->assertCount(1, $reserved);
        $this->assertSame(mb_strlen($this->provider->requests[count($this->provider->requests) - 1]->text), $reserved[0]);
        $this->assertSame($charsBefore + $reserved[0], $this->today()['chars']);
    }

    /** Test 56 (case 62). */
    public function testSettlementAndRelease(): void
    {
        $this->provider->billedOverride = 7;
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->run_($post->ID);
        $this->assertSame(7, $this->today()['chars']);
        $this->assertSame(0, $this->today()['reserved_chars']);

        $this->provider->billedOverride = null;
        $failing = $this->post(['post_title' => 'Fails']);
        $this->provider->script = [new \IndianOpinion\ReadAloud\Exception\TransientProviderException('timeout')];
        $this->trigger($failing->ID, 'generate');
        $this->assertSame('retrying', $this->run_($failing->ID));
        $this->assertSame(7, $this->today()['chars'], 'an unbilled failure releases its share');
        $this->assertSame(0, $this->today()['reserved_chars']);

        $day = $this->plugin->days()->today();
        $this->ledger->reserve($day, 500, 0, new Caps(50000, 20), false, $this->clock->now(), 9);
        $this->assertSame(500, $this->today()['reserved_chars']);
        $this->clock->advance(3601);
        $this->plugin->maintenance()->daily();
        $this->assertSame(0, $this->ledger->usage($day)['reserved_chars']);
        $this->assertContains('reservations_released', array_column($this->plugin->log()->recent(50), 'type'));
    }

    /** Test 57 (case 63). */
    public function testPostLargerThanCap(): void
    {
        $this->settings(['cap_chars' => 300]);
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('skipped', $this->run_($post->ID));
        $this->assertSame('exceeds_daily_cap', $this->record($post->ID)->reason);
        $this->assertSame('failed', $this->trigger($post->ID, 'update'), 'it does not block the queue every day');
        $this->trigger($post->ID, 'cli', ['force' => true, 'over_cap' => true]);
        $this->assertSame('done', $this->run_($post->ID));
    }

    /** Test 58 (case 64). */
    public function testWarningAndReachedFireOncePerCapPerDay(): void
    {
        $warnings = [];
        $reached = [];
        add_action('read_aloud_cap_warning', function (string $cap) use (&$warnings): void {
            $warnings[] = $cap;
        });
        add_action('read_aloud_cap_reached', function (string $cap) use (&$reached): void {
            $reached[] = $cap;
        });
        $this->settings(['cap_generations' => 5, 'cap_chars' => 1000000]);
        for ($i = 0; $i < 6; $i++) {
            $post = $this->post(['post_title' => 'Story ' . $i]);
            $this->trigger($post->ID, 'generate');
            $this->run_($post->ID);
        }
        $this->assertSame(['generations'], array_values(array_unique($warnings)));
        $this->assertCount(1, $warnings);
        $this->assertSame(['generations'], $reached);
        \WpStub::$conditionals['is_admin'] = true;
        \WpStub::$currentUser = self::ADMIN;
        $this->assertStringContainsString('reached a daily cap', (new \IndianOpinion\ReadAloud\Admin\Notices($this->plugin))->html());
    }

    /** Test 59 (case 65). */
    public function testCountersTrimmedAfterThirtyDays(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->ledger->incrementSavesSkipped($this->plugin->days()->daysAgo($i));
        }
        $this->plugin->maintenance()->daily();
        $this->assertCount(30, $this->ledger->days);
        $this->assertCount(30, $this->plugin->statusData()['history']);
    }
}
