<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Integrations\GenQueue;

/**
 * PRD 12.9, cases 72 to 74.
 */
final class SoftIntegrationsTest extends TestCase
{
    /** Test 66 (case 72): gen-queue soft enqueue, published 0.x contract. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testIdempotencyKeyAndNoDuplicate(): void
    {
        define('GEN_QUEUE_VERSION', '0.1.0');
        $calls = [];
        add_filter('gen_queue_enqueue_job', function ($result, $type, $payload, $args) use (&$calls) {
            $calls[] = [$type, $payload, $args];
            return count($calls);
        }, 10, 4);
        $post = $this->post();
        $this->trigger($post->ID, 'update');
        $this->trigger($post->ID, 'update');
        $hash = $this->plugin->planner()->plan($post)->contentHash;
        $this->assertCount(1, $calls, 'unchanged text is not enqueued twice');
        [$type, $payload, $args] = $calls[0];
        $this->assertSame(GenQueue::TYPE, $type);
        $this->assertSame('read-aloud.generate', $type);
        $this->assertSame($post->ID, $payload['post_id']);
        $this->assertSame($hash, $payload['content_hash']);
        $runAt = $this->record($post->ID)->nextAttemptAt;
        $this->assertSame($runAt, $args['run_after']);
        $this->assertSame(GenQueue::key($post->ID, $hash, $runAt), $args['idempotency_key']);
        $this->assertSame($post->ID . ':' . $hash . ':' . $runAt, $args['idempotency_key']);
        $this->assertSame('read-aloud:' . $post->ID, $args['source']);
        $this->assertSame('0', $args['cost_estimate'], 'no price set, no money estimate');
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'), 'the queue took the job, not WP-Cron');

        // A WP_Error or null from the filter falls back to WP-Cron.
        remove_all_filters('gen_queue_enqueue_job');
        add_filter('gen_queue_enqueue_job', fn () => new \WP_Error('gen_queue_invalid', 'no'), 10, 4);
        $other = $this->post(['post_title' => 'Other']);
        $this->trigger($other->ID, 'update');
        $this->assertCount(1, \WpStub::cronFor('read_aloud_job'));
    }

    /** Test 66b (case 72, 74): a gen-queue of another major version is absent. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testOtherQueueMajorVersionIsAbsent(): void
    {
        define('GEN_QUEUE_VERSION', '1.0.0');
        $asked = 0;
        add_filter('gen_queue_enqueue_job', function () use (&$asked) {
            $asked++;
            return 1;
        }, 10, 4);
        $post = $this->post();
        $this->trigger($post->ID, 'update');
        $this->assertSame(0, $asked);
        $this->assertCount(1, \WpStub::cronFor('read_aloud_job'));
    }

    /** Test 66c (case 72): the handler is registered with gen_queue_register_type and runs the job. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testQueueHandlerRegistrationAndRun(): void
    {
        $GLOBALS['read_aloud_test_types'] = [];
        require_once __DIR__ . '/Support/gen-queue-functions.php';
        $this->plugin->register(READ_ALOUD_FILE);
        do_action('gen_queue_register_types');
        $types = $GLOBALS['read_aloud_test_types'];
        $this->assertArrayHasKey(GenQueue::TYPE, $types);
        $this->assertSame(1, $types[GenQueue::TYPE][1]['max_attempts']);

        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $job = new \stdClass();
        $job->payload = ['post_id' => $post->ID];
        $result = $types[GenQueue::TYPE][0]($job, null);
        $this->assertSame(['outcome' => 'done'], $result);
        $this->assertSame(Status::PENDING_REVIEW, $this->record($post->ID)->status);

        // A job whose run time moved on is left for its own run.
        $this->edit($post, self::blocks([self::words(400, 'harbour')]));
        $this->trigger($post->ID, 'update');
        $this->assertSame(['outcome' => 'not_due'], $types[GenQueue::TYPE][0]($job, null));
    }

    /** Test 67 (case 73): spend-meter contract 1. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testMeterDenyAllowAndOwnCap(): void
    {
        define('SPEND_METER_API', 1);
        $asks = [];
        $records = [];
        $releases = [];
        $allow = false;
        add_filter('spend_meter_pre_spend', function ($result, array $intent) use (&$asks, &$allow) {
            $asks[] = $intent;
            return [
                'allowed' => $allow,
                'reason' => $allow ? 'ok' : 'cap_site_day',
                'message' => '',
                'reservation_id' => $allow ? 'm-' . count($asks) : null,
                'estimate' => 0,
                'remaining' => null,
                'api' => 1,
            ];
        }, 10, 2);
        add_action('spend_meter_record', function (array $call) use (&$records): void {
            $records[] = $call;
        });
        add_action('spend_meter_release', function ($reservationId) use (&$releases): void {
            $releases[] = $reservationId;
        });

        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('capped', $this->run_($post->ID));
        $this->assertSame('meter:cap_site_day', $this->record($post->ID)->reason);
        $this->assertSame(Status::CAPPED, $this->record($post->ID)->status);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame('read-aloud', $asks[0]['purpose']);
        $this->assertSame('read-aloud', $asks[0]['caller']);
        $this->assertSame('fake', $asks[0]['provider']);
        $this->assertSame('m1', $asks[0]['model']);
        $this->assertGreaterThan(0, $asks[0]['est_units']);
        $this->assertSame([], $releases, 'a denied check holds nothing to release');

        $allow = true;
        $this->assertSame('done', $this->run_($post->ID));
        $this->assertSame('m-2', $records[0]['reservation_id']);
        $this->assertGreaterThan(0, $records[0]['units']);
        $this->assertSame('ok', $records[0]['status']);
        $this->assertSame(200, $records[0]['http_status']);
        $this->assertSame('fake', $records[0]['provider']);
        $this->assertArrayHasKey('latency_ms', $records[0]);
        $this->assertSame([], $releases, 'a used reservation is recorded, not released');

        $this->settings(['cap_generations' => 1]);
        $other = $this->post(['post_title' => 'Other']);
        $this->trigger($other->ID, 'generate');
        $this->assertSame('capped', $this->run_($other->ID), 'own cap still stops a job the meter allows');
        $this->assertSame('cap', $this->record($other->ID)->reason);
        $this->assertSame(['m-3'], $releases, 'released as a plain reservation id string');
    }

    /** Test 67b (case 73): a decision without allowed === true is a deny; null means no meter. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testMeterLooseAnswersDenyAndNullIsAbsent(): void
    {
        define('SPEND_METER_API', 1);
        $answer = ['allowed' => 1, 'reason' => 'ok', 'reservation_id' => 'x'];
        add_filter('spend_meter_pre_spend', function () use (&$answer) {
            return $answer;
        }, 10, 2);
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('capped', $this->run_($post->ID), 'allowed must be exactly true');
        $this->assertSame([], $this->provider->requests);

        $answer = false;
        $this->assertSame('capped', $this->run_($post->ID));
        $this->assertSame('meter:invalid_decision', $this->record($post->ID)->reason);

        $answer = null;
        $this->assertSame('done', $this->run_($post->ID), 'null: no meter answered, own caps only');
    }

    /** Test 67c (case 73, 81): a call that fails is recorded once, billed or not. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testMeterRecordsFailedCalls(): void
    {
        define('SPEND_METER_API', 1);
        $records = [];
        $releases = [];
        add_filter('spend_meter_pre_spend', fn () => ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r-1'], 10, 2);
        add_action('spend_meter_record', function (array $call) use (&$records): void {
            $records[] = $call;
        });
        add_action('spend_meter_release', function ($id) use (&$releases): void {
            $releases[] = $id;
        });
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->provider->script = [new \IndianOpinion\ReadAloud\Exception\TransientProviderException('busy', null, 503)];
        $this->assertSame('retrying', $this->run_($post->ID));
        $this->assertCount(1, $records);
        $this->assertSame('error_unbilled', $records[0]['status']);
        $this->assertSame(503, $records[0]['http_status']);
        $this->assertSame([], $releases, 'one record or one release, never both');
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
