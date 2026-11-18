<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Integrations\GenQueue;

/**
 * PRD 12.9, cases 72 to 74.
 */
final class IntegrationsTest extends TestCase
{
    /** Test 66 (case 72). */
    public function testIdempotencyKeyAndNoDuplicate(): void
    {
        $keys = [];
        add_filter('gen_queue_enqueue_job', function ($result, array $job) use (&$keys) {
            $keys[] = $job['idempotency_key'];
            return 'id';
        }, 10, 2);
        $post = $this->post();
        $this->trigger($post->ID, 'update');
        $this->trigger($post->ID, 'update');
        $hash = $this->plugin->planner()->plan($post)->contentHash;
        $this->assertSame([GenQueue::key($post->ID, $hash)], $keys);
        $this->assertSame($post->ID . ':' . $hash, $keys[0]);
    }

    /** Test 67 (case 73). */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testMeterDenyAllowAndOwnCap(): void
    {
        define('SPEND_METER_API', 1);
        $asks = [];
        $records = [];
        $releases = [];
        $allow = false;
        add_filter('spend_meter_pre_spend', function ($result, array $ask) use (&$asks, &$allow) {
            $asks[] = $ask;
            return $allow ? ['allow' => true, 'reservation_id' => 'm-1'] : new \WP_Error('budget_exhausted', 'No budget');
        }, 10, 2);
        add_action('spend_meter_record', function (array $r) use (&$records): void {
            $records[] = $r;
        });
        add_action('spend_meter_release', function (array $r) use (&$releases): void {
            $releases[] = $r;
        });

        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('capped', $this->run_($post->ID));
        $this->assertSame('meter:budget_exhausted', $this->record($post->ID)->reason);
        $this->assertSame(Status::CAPPED, $this->record($post->ID)->status);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame('read-aloud', $asks[0]['purpose']);
        $this->assertSame('read-aloud', $asks[0]['caller']);
        $this->assertSame('fake', $asks[0]['provider']);
        $this->assertSame('m1', $asks[0]['model']);
        $this->assertGreaterThan(0, $asks[0]['est_units']);

        $allow = true;
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertSame('m-1', $records[0]['reservation_id']);
        $this->assertGreaterThan(0, $records[0]['units']);
        $this->assertSame('ok', $records[0]['status']);
        $this->assertSame(200, $records[0]['http_status']);
        $this->assertArrayHasKey('latency_ms', $records[0]);

        $this->settings(['cap_generations' => 1]);
        $other = $this->post(['post_title' => 'Other']);
        $this->trigger($other->ID, 'generate');
        $this->assertSame('capped', $this->run_($other->ID), 'own cap still stops a job the meter allows');
        $this->assertSame('cap', $this->record($other->ID)->reason);
        $this->assertSame('m-1', $releases[0]['reservation_id']);
    }

    /** Test 68 (case 74). */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testWrongMeterVersionIsAbsentAndLoggedOnce(): void
    {
        define('SPEND_METER_API', 99);
        $asked = 0;
        add_filter('spend_meter_pre_spend', function ($result) use (&$asked) {
            $asked++;
            return new \WP_Error('deny', 'deny');
        });
        for ($i = 0; $i < 2; $i++) {
            $post = $this->post(['post_title' => 'Story ' . $i]);
            $this->trigger($post->ID, 'generate');
            $this->assertSame('done', $this->run_($post->ID));
        }
        $this->assertSame(0, $asked);
        $absent = array_filter($this->plugin->log()->recent(200), fn (array $e): bool => $e['type'] === 'integration_absent' && ($e['data']['integration'] ?? '') === 'spend-meter');
        $this->assertCount(1, $absent);
        $this->assertSame('version', array_values($absent)[0]['data']['state']);
    }
}
