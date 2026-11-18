<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Admin\StatusPage;
use IndianOpinion\ReadAloud\Cli\Command;
use IndianOpinion\ReadAloud\Data\Status;

/**
 * PRD 12.10, cases 75 to 77.
 */
final class BackfillTest extends TestCase
{
    /** Test 69 (case 75). */
    public function testDryRunLimitAndOutdated(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->post(['post_title' => 'Old ' . $i, 'post_date' => '2026-0' . ($i + 1) . '-10 09:00:00'])->ID;
        }
        $command = new Command($this->plugin);
        $command->backfill([], ['dry-run' => true, 'limit' => '3']);
        $this->assertCount(3, \WP_CLI::$items);
        $this->assertGreaterThan(0, \WP_CLI::$items[0]['chars']);
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
        $this->assertSame(Status::NONE, $this->record($ids[0])->status);

        $approved = $this->approvedPost(['post_title' => 'Done', 'post_date' => '2025-01-01 00:00:00']);
        $this->settings(['voice_map' => [['locale' => 'en-GB', 'voice' => 'other', 'model' => '', 'speed' => 1.0, 'format' => 'mp3']]]);
        $this->plugin->maintenance()->markOutdated();
        $plain = array_column($this->plugin->queue()->backfill(['dry_run' => true, 'limit' => 50]), 'post');
        $this->assertNotContains($approved->ID, $plain);
        $outdated = array_column($this->plugin->queue()->backfill(['dry_run' => true, 'limit' => 50, 'outdated' => true]), 'post');
        $this->assertContains($approved->ID, $outdated);

        $queued = $this->plugin->queue()->backfill(['limit' => 2, 'after' => '2026-03-01']);
        $this->assertCount(2, $queued);
        $this->assertSame(Status::QUEUED, $this->record($queued[0]['post'])->status);
    }

    /** Test 70 (case 76). */
    public function testDaysToDrainEstimate(): void
    {
        $this->settings(['cap_chars' => 1000]);
        $chars = 0;
        for ($i = 0; $i < 4; $i++) {
            $post = $this->post(['post_title' => 'Old ' . $i]);
            $chars += $this->plugin->planner()->plan($post)->chars;
        }
        $this->plugin->queue()->backfill(['limit' => 10]);
        $data = $this->plugin->statusData();
        $this->assertSame($chars, $data['queued_chars']);
        $this->assertSame((int) ceil($chars / 1000), $data['days_to_drain']);
        $this->assertSame(4, $data['queue_length']);
        \WpStub::$currentUser = self::ADMIN;
        $this->assertStringContainsString('Estimated days to drain at the current cap: ' . (int) ceil($chars / 1000), (new StatusPage($this->plugin))->html());
    }

    /** Test 71 (case 77). */
    public function testCancelRemovesBackfillJobsOnly(): void
    {
        $old = $this->post(['post_title' => 'Old']);
        $this->plugin->queue()->backfill(['limit' => 10]);
        $fresh = $this->post(['post_title' => 'Fresh']);
        $this->trigger($fresh->ID, 'publish');
        $command = new Command($this->plugin);
        $command->cancel_backfill([], []);
        $this->assertStringContainsString('1 backfill jobs removed', implode("\n", \WP_CLI::$lines));
        $this->assertSame(Status::NONE, $this->record($old->ID)->status);
        $this->assertSame(Status::QUEUED, $this->record($fresh->ID)->status);
        $this->assertSame([[$fresh->ID]], array_column(\WpStub::cronFor('read_aloud_job'), 2));

        // A running backfill job finishes its current chunk, then stops.
        $paragraphs = [];
        foreach (['a', 'b', 'c'] as $seed) {
            $paragraphs[] = self::words(850, $seed);
        }
        $long = $this->post(['post_title' => 'Long', 'post_content' => self::blocks($paragraphs)]);
        $this->plugin->queue()->backfill(['limit' => 1]);
        $this->provider->onCall = function (): void {
            $this->plugin->queue()->cancelBackfill();
        };
        $this->assertSame('cancelled', $this->run_($long->ID));
        $this->assertCount(1, $this->provider->requests);
        $this->assertSame(0, $this->today()['reserved_chars']);
    }
}
