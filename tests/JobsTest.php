<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Tests;

use IndianOpinion\ReadAloud\Audio\Mp3;
use IndianOpinion\ReadAloud\Audio\Wav;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Integrations\GenQueue;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Tests\Support\FakeProvider;

/**
 * PRD 12.6, cases 48 to 57.
 */
final class JobsTest extends TestCase
{
    /** Test 43 (case 48). */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testCronWithoutQueueAndSoftFilterWithQueue(): void
    {
        $post = $this->post();
        $this->trigger($post->ID);
        $this->assertCount(1, \WpStub::cronFor('read_aloud_job'));

        \WpStub::$cron = [];
        define('GEN_QUEUE_VERSION', '0.1.0');
        $jobs = [];
        add_filter('gen_queue_enqueue_job', function ($result, string $type, array $payload, array $args) use (&$jobs) {
            $jobs[] = ['type' => $type, 'payload' => $payload, 'args' => $args];
            return count($jobs);
        }, 10, 4);
        $this->settings(['price_per_million' => '20']);
        $other = $this->post(['post_title' => 'Other']);
        $this->trigger($other->ID);
        $this->assertCount(1, $jobs);
        $this->assertSame(GenQueue::TYPE, $jobs[0]['type']);
        $this->assertGreaterThan(0, $jobs[0]['payload']['chars']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $jobs[0]['args']['cost_estimate']);
        $this->assertNotSame('0', $jobs[0]['args']['cost_estimate'], 'with a price the queue gets a money estimate');
        $this->assertSame([], \WpStub::cronFor('read_aloud_job'));
    }

    /** Test 44 (case 49). */
    public function testLocksAndTakeover(): void
    {
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->ledger->lock('post-' . $post->ID, 'other-runner', $this->clock->now(), 900);
        $this->assertSame('locked', $this->run_($post->ID));
        $this->assertSame([], $this->provider->requests);

        $this->clock->advance(901);
        $this->assertSame('done', $this->run_($post->ID));
        $types = array_column($this->plugin->log()->recent(50), 'type');
        $this->assertContains('lock_takeover', $types);

        $this->settings(['concurrency' => 1]);
        $this->ledger->lock('slot-1', 'busy-runner', $this->clock->now(), 900);
        $this->assertSame('busy', $this->plugin->runner()->runOne($post->ID, null));
    }

    /** Test 45 (case 50). */
    public function testJobRechecksBeforeAnyCall(): void
    {
        $post = $this->post();
        $this->trigger($post->ID);
        $post->post_status = 'draft';
        $this->assertSame('ineligible', $this->run_($post->ID));

        $approved = $this->approvedPost(['post_title' => 'Approved']);
        $original = $approved->post_content;
        $calls = count($this->provider->requests);
        $this->edit($approved, $original . self::blocks(['An extra line.']));
        $this->assertSame('queued', $this->trigger($approved->ID, 'update'));
        $approved->post_content = $original;
        $this->assertSame('nothing', $this->run_($approved->ID));
        $this->assertSame($calls, count($this->provider->requests));
    }

    /** Test 46 (case 51). */
    public function testRequestCarriesVoiceSettings(): void
    {
        $this->settings(['voice_map' => [['locale' => 'en-GB', 'voice' => 'brit', 'model' => 'hd', 'speed' => 1.5, 'format' => 'wav']]]);
        $post = $this->post();
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $request = $this->provider->requests[0];
        $this->assertSame(['brit', 'hd', 1.5, 'wav', 'en-GB'], [$request->voice, $request->model, $request->speed, $request->format, $request->locale]);
        $this->assertSame(1, preg_match('/^[0-9a-f-]{36}$/', $request->requestId));
    }

    /** Test 47 (case 52). */
    public function testNonAudioResponsesAreInvalid(): void
    {
        $cases = [
            'html' => fn (SpeechRequest $r) => FakeProvider::bytes($r, '<!DOCTYPE html><html>' . str_repeat('x', 100000)),
            'json' => fn (SpeechRequest $r) => FakeProvider::bytes($r, '{"error": "quota"}' . str_repeat(' ', 100000)),
            'short' => fn (SpeechRequest $r) => FakeProvider::bytes($r, Mp3::silence(0.1)),
            'wrong' => fn (SpeechRequest $r) => FakeProvider::bytes($r, Wav::silence(30)),
        ];
        foreach ($cases as $name => $behaviour) {
            $post = $this->post(['post_title' => 'Invalid ' . $name]);
            $this->provider->script = [$behaviour];
            $this->trigger($post->ID, 'generate');
            $this->assertSame('retrying', $this->run_($post->ID), $name);
            $this->assertSame('invalid_audio', $this->record($post->ID)->lastErrorCode, $name);
        }
        $this->assertSame([], glob($this->uploads . '/read-aloud/*/*/*') ?: []);
    }

    /** Test 48 (cases 53, 54). */
    public function testRenameAndNewUrlPerVersion(): void
    {
        $post = $this->approvedPost();
        $first = $this->record($post->ID);
        $path = $this->uploads . '/read-aloud/' . $first->fileKey;
        $this->assertFileExists($path);
        $this->assertSame([], glob(dirname($path) . '/*.part') ?: []);
        $this->assertSame(1, preg_match('~^\d{4}/\d{2}/' . $post->ID . '-[0-9a-f]{12}\.mp3$~', $first->fileKey));
        $bytes = (string) file_get_contents($path);

        $this->edit($post, str_replace('council', 'committee', $post->post_content));
        $this->trigger($post->ID, 'update');
        $this->assertSame('done', $this->run_($post->ID));
        $pending = $this->record($post->ID)->pending;
        $this->assertNotSame($first->fileKey, $pending['file_key']);
        $this->assertNotSame($first->fileUrl, $pending['file_url']);
        $this->assertSame($bytes, file_get_contents($path), 'an existing URL never changes bytes');

        $store = $this->plugin->store();
        $other = $this->dir . '/other.mp3';
        file_put_contents($other, Mp3::silence(9));
        $this->expectException(\IndianOpinion\ReadAloud\Exception\StorageException::class);
        $store->put($other, $first->fileKey, 'audio/mpeg');
    }

    /** Test 49 (cases 55, 56). */
    public function testSuccessRecordsEveryFieldAndKeepsPreviousLive(): void
    {
        $fired = [];
        add_action('read_aloud_generated', function (int $postId, $record) use (&$fired): void {
            $fired[] = $postId;
        }, 10, 2);
        $post = $this->approvedPost();
        $live = $this->record($post->ID);
        $this->edit($post, str_replace('council', 'committee', $post->post_content));
        $this->trigger($post->ID, 'update');
        $this->run_($post->ID);
        $record = $this->record($post->ID);
        $this->assertSame(Status::PENDING_REVIEW, $record->status);
        foreach (['file_key', 'file_url', 'mime', 'bytes', 'duration', 'chars', 'provider', 'voice', 'model', 'locale', 'fingerprint', 'content_hash', 'render_hash', 'normaliser_version', 'generated_at'] as $field) {
            $this->assertNotEmpty($record->pending[$field] ?? null, $field);
        }
        $this->assertSame('audio/mpeg', $record->pending['mime']);
        $this->assertSame([$post->ID, $post->ID], $fired);
        $this->assertSame($live->fileKey, $record->fileKey);
        $this->settings(['stale_policy' => 'note']);
        $this->assertStringContainsString($live->fileKey, read_aloud_player($post->ID));
    }

    /** Test 50 (case 57). */
    public function testEditDuringJobKeepsResultAndQueuesAgain(): void
    {
        $post = $this->post();
        $this->provider->onCall = function () use ($post): void {
            $post->post_content .= self::blocks(['Added while the job ran.']);
            $this->provider->onCall = null;
        };
        $this->trigger($post->ID, 'generate');
        $this->assertSame('done', $this->run_($post->ID));
        $record = $this->record($post->ID);
        $this->assertSame(Status::QUEUED, $record->status);
        $this->assertNotNull($record->pending);
        $this->assertCount(1, \WpStub::cronFor('read_aloud_job'));
        $this->assertGreaterThan($this->clock->now(), \WpStub::cronFor('read_aloud_job')[0][0]);
    }
}
