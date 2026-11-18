<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Jobs;

use IndianOpinion\ReadAloud\Audio\AudioFormat;
use IndianOpinion\ReadAloud\Audio\Joiner;
use IndianOpinion\ReadAloud\Clock\Clock;
use IndianOpinion\ReadAloud\Control\Guard;
use IndianOpinion\ReadAloud\Data\AudioRecord;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Exception\AccountException;
use IndianOpinion\ReadAloud\Exception\FormatNotJoinableException;
use IndianOpinion\ReadAloud\Exception\InvalidAudioException;
use IndianOpinion\ReadAloud\Exception\ProviderConfigException;
use IndianOpinion\ReadAloud\Exception\ReadAloudException;
use IndianOpinion\ReadAloud\Exception\RejectedTextException;
use IndianOpinion\ReadAloud\Exception\StorageException;
use IndianOpinion\ReadAloud\Exception\TransientProviderException;
use IndianOpinion\ReadAloud\Integrations\SpendMeter;
use IndianOpinion\ReadAloud\Ledger\CapPolicy;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Provider\ProviderRegistry;
use IndianOpinion\ReadAloud\Provider\SpeechProvider;
use IndianOpinion\ReadAloud\Provider\SpeechRequest;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Storage\AudioStore;
use IndianOpinion\ReadAloud\Storage\ChunkCache;
use IndianOpinion\ReadAloud\Support\Days;
use IndianOpinion\ReadAloud\Support\EventLog;
use IndianOpinion\ReadAloud\Support\Redactor;
use IndianOpinion\ReadAloud\Support\TempFiles;
use IndianOpinion\ReadAloud\Text\Chunker;
use IndianOpinion\ReadAloud\Text\Hashes;
use IndianOpinion\ReadAloud\Text\Normaliser;

/**
 * Runs one generation job (PRD 4.5 to 4.7 and 4.11). Always off the request.
 */
final class Generator
{
    public const RETRY_DELAYS = [300, 1800, 7200];
    public const MAX_RETRY_AFTER = 21600;
    public const LOCK_TTL = 900;
    public const TEST_TEXT = 'This is a test of the read aloud voice. If you can hear this sentence clearly, the settings work ok.';

    public function __construct(
        private readonly Settings $settings,
        private readonly Records $records,
        private readonly Planner $planner,
        private readonly Queue $queue,
        private readonly Dispatcher $dispatcher,
        private readonly ProviderRegistry $providers,
        private readonly AudioStore $store,
        private readonly ChunkCache $cache,
        private readonly Ledger $ledger,
        private readonly CapPolicy $capPolicy,
        private readonly Guard $guard,
        private readonly SpendMeter $meter,
        private readonly Days $days,
        private readonly Clock $clock,
        private readonly EventLog $log,
        private readonly Joiner $joiner = new Joiner()
    ) {
    }

    /** Job time budget: 120 seconds, or 80 percent of max_execution_time if lower. */
    public static function defaultBudget(): int
    {
        $limit = (int) ini_get('max_execution_time');
        $budget = 120;
        if ($limit > 0) {
            $budget = min($budget, (int) floor($limit * 0.8));
        }

        return max(10, $budget);
    }

    public function provider(): SpeechProvider
    {
        return $this->providers->make($this->settings->string('provider'), $this->settings->providerSettings());
    }

    /**
     * Run the job for one post.
     *
     * @param int|null $deadline Unix time after which no new chunk is started; null for no limit.
     * @return string done, nothing, locked, stopped_kill, held, capped, skipped, ineligible, retrying, failed, refused, stopped_time, cancelled
     */
    public function run(int $postId, ?int $deadline = null): string
    {
        $owner = bin2hex(random_bytes(16));
        $lockName = 'post-' . $postId;
        $lock = $this->ledger->lock($lockName, $owner, $this->clock->now(), self::LOCK_TTL);
        if ($lock === 'busy') {
            return 'locked';
        }
        if ($lock === 'taken_over') {
            $this->log->add('lock_takeover', $postId, ['lock' => $lockName]);
        }
        try {
            return $this->runLocked($postId, $deadline);
        } finally {
            $this->ledger->unlock($lockName, $owner);
        }
    }

    private function runLocked(int $postId, ?int $deadline): string
    {
        $record = $this->records->get($postId);
        $job = is_array($record['job']) ? $record['job'] : null;
        if ($job === null) {
            return 'nothing';
        }
        if (!$this->guard->enabled()) {
            return 'stopped_kill';
        }
        if ($this->guard->isHeld()) {
            if ($record['status'] !== Status::HELD) {
                $record['status'] = Status::HELD;
                $this->records->save($postId, $record);
            }
            return 'held';
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            $this->queue->dropJob($postId, $record);
            $this->records->save($postId, $record);
            return 'ineligible';
        }
        $plan = $this->planner->plan($post);
        if (!$plan->eligible) {
            $this->queue->dropJob($postId, $record);
            $record['status'] = $plan->isSkip() ? Status::SKIPPED : Queue::restingStatus($record);
            $record['reason'] = $plan->reason;
            $this->records->save($postId, $record);
            $this->log->add('skipped', $postId, ['reason' => $plan->reason, 'stage' => 'job']);
            return 'ineligible';
        }
        $target = $plan->contentHash;
        $record['current_hash'] = $target;
        if (empty($job['force']) && $record['file_key'] !== '' && $record['content_hash'] === $target) {
            $this->queue->dropJob($postId, $record);
            $record['status'] = Queue::restingStatus($record);
            $this->records->save($postId, $record);
            return 'nothing';
        }
        $voice = $plan->voice;
        if ($voice === null) {
            return 'ineligible';
        }

        $provider = null;
        try {
            $provider = $this->provider();
            $format = $voice['format'];
            if (!in_array($format, $provider->formats(), true)) {
                throw new ProviderConfigException('The provider does not offer the ' . $format . ' format.');
            }
            $chunks = (new Chunker($provider->maxChars(), [$provider, 'countChars']))->chunk($this->planner->synthesisText($plan->text));
            if ($chunks === []) {
                throw new ProviderConfigException('Nothing to read after the lexicon was applied.');
            }
            if (count($chunks) > 1 && !AudioFormat::pluginCanJoin($format) && !$provider->canJoin($format)) {
                throw new FormatNotJoinableException('This text needs ' . count($chunks) . ' requests and ' . $format . ' chunks cannot be joined.');
            }
        } catch (ReadAloudException $e) {
            return $this->fail($postId, $record, $target, $e->errorCode(), $e->getMessage(), $provider, $e);
        }

        // "Generate now" asks for a fresh voice: cached chunks are not reused.
        $fresh = !empty($job['fresh']);
        $hashes = [];
        $missingChars = 0;
        foreach ($chunks as $i => $chunk) {
            $hashes[$i] = Hashes::chunk($chunk, $plan->renderHash);
            if ($fresh || !$this->cache->has($postId, $hashes[$i], $format)) {
                $missingChars += $provider->countChars($chunk);
            }
        }
        $caps = $this->capPolicy->caps();
        $overCap = !empty($job['over_cap']);
        if ($missingChars > $caps->chars && !$overCap) {
            $this->queue->dropJob($postId, $record);
            $record['status'] = Status::SKIPPED;
            $record['reason'] = 'exceeds_daily_cap';
            $record['failed_hash'] = $target;
            $this->records->save($postId, $record);
            $this->log->add('skipped', $postId, ['reason' => 'exceeds_daily_cap', 'chars' => $missingChars]);
            return 'skipped';
        }

        $meterReservation = '';
        $meterState = $this->meter->state();
        if ($meterState === 'present' && $missingChars > 0) {
            $decision = $this->meter->preSpend($provider->id(), $voice['model'] !== '' ? $voice['model'] : $voice['voice'], $missingChars);
            if (!$decision['allow']) {
                return $this->defer($postId, $record, 'meter:' . ($decision['reason'] !== '' ? $decision['reason'] : 'denied'), $target);
            }
            $meterReservation = $decision['reservation'];
        } elseif ($meterState !== 'present' && $this->ledger->setFlag($this->days->today(), Ledger::FLAG_LOG_SPEND_METER)) {
            $this->log->add('integration_absent', 0, ['integration' => 'spend-meter', 'state' => $meterState]);
        }

        $billed = $provider->isBilled();
        $day = $this->days->today();
        $reservation = $this->ledger->reserve($day, $missingChars, $billed ? $this->capPolicy->moneyFor($missingChars) : 0, $caps, $overCap, $this->clock->now(), $postId);
        if ($reservation === null) {
            if ($meterReservation !== '') {
                $this->meter->release($meterReservation);
            }
            $this->capPolicy->notify($day, ['chars' => $missingChars, 'money' => $billed ? $this->capPolicy->moneyFor($missingChars) : 0]);
            return $this->defer($postId, $record, 'cap', $target);
        }
        $this->capPolicy->notify($day);
        $record['status'] = Status::GENERATING;
        $record['reservation'] = $reservation;
        $this->records->save($postId, $record);
        $this->log->add('started', $postId, ['chunks' => count($chunks), 'chars' => $missingChars]);

        $paths = [];
        $temps = [];
        $providerDuration = 0.0;
        $allDurations = true;
        $billedTotal = 0;
        $jobId = (string) ($job['id'] ?? '');
        try {
            foreach ($chunks as $i => $chunk) {
                $cached = $fresh ? null : $this->cache->get($postId, $hashes[$i], $format);
                if ($cached !== null) {
                    $paths[$i] = $cached;
                    $allDurations = false;
                    $this->log->add('chunk_reused', $postId, ['chunk' => $i + 1]);
                    continue;
                }
                $stop = $this->stopReason($postId, $jobId, $deadline);
                if ($stop !== null) {
                    $this->ledger->release($reservation);
                    if ($meterReservation !== '') {
                        $this->meter->release($meterReservation);
                    }
                    return $this->stop($postId, $stop);
                }
                $chars = $provider->countChars($chunk);
                $request = new SpeechRequest(
                    $chunk,
                    $voice['voice'],
                    $voice['model'],
                    $plan->locale,
                    $format,
                    $voice['speed'],
                    SpeechRequest::newRequestId(),
                    $this->settings->int('request_timeout')
                );
                $started = microtime(true);
                try {
                    $audio = $provider->synthesize($request);
                } catch (ReadAloudException $e) {
                    $this->settleFailure($reservation, $chars, $meterReservation, $e, $started);
                    $meterReservation = '';
                    throw $e;
                } catch (\Throwable $e) {
                    $this->ledger->settle($reservation, $chars, 0, $this->capPolicy->moneyFor($chars), 0);
                    throw new UnexpectedProviderError(Redactor::clean(get_class($e) . ': ' . $e->getMessage(), [$this->settings->apiKey()]), 0, $e);
                }
                $temps[] = $audio->path;
                try {
                    if ($audio->format !== $format) {
                        throw new InvalidAudioException('The provider returned ' . $audio->format . ' for a ' . $format . ' request.');
                    }
                    AudioFormat::validateFile($audio->path, $format, $chars);
                } catch (InvalidAudioException $e) {
                    $this->settleFailure($reservation, $chars, $meterReservation, $e, $started);
                    $meterReservation = '';
                    throw $e;
                }
                $billedChars = $audio->billedChars ?? $chars;
                $billedTotal += $billedChars;
                $this->ledger->settle(
                    $reservation,
                    $chars,
                    $billedChars,
                    $billed ? $this->capPolicy->moneyFor($chars) : 0,
                    $billed ? $this->capPolicy->moneyFor($billedChars) : 0
                );
                $latency = (int) round((microtime(true) - $started) * 1000);
                if ($meterReservation !== '') {
                    $this->meter->record($meterReservation, $billedChars, 'ok', 200, $latency);
                }
                $this->log->add('chunk_sent', $postId, ['chunk' => $i + 1, 'chars' => $billedChars, 'latency_ms' => $latency, 'http' => 200]);
                if ($audio->durationSeconds !== null && $audio->durationSeconds > 0) {
                    $providerDuration += $audio->durationSeconds;
                } else {
                    $allDurations = false;
                }
                if ($this->cache->enabled()) {
                    $paths[$i] = $this->cache->put($postId, $hashes[$i], $format, $audio->path);
                } else {
                    $paths[$i] = $audio->path;
                }
            }

            ksort($paths);
            $joined = TempFiles::create($format);
            $temps[] = $joined;
            $measured = $this->joiner->join(array_values($paths), $format, $joined, null, $provider->canJoin($format));
            $duration = $allDurations && $providerDuration > 0 ? $providerDuration : $measured;
            // A fresh run of unchanged text gets its own URL: bytes behind a URL never change.
            $fingerprint = Hashes::fingerprint($target, $plan->renderHash . ($fresh ? $jobId : ''));
            $key = self::fileKey($post, $fingerprint, $format);
            $stored = $this->store->put($joined, $key, AudioFormat::mime($format));
        } catch (ReadAloudException $e) {
            $this->ledger->release($reservation);
            if ($meterReservation !== '') {
                $this->meter->release($meterReservation);
            }
            return $this->fail($postId, $this->records->get($postId), $target, $e->errorCode(), $e->getMessage(), $provider, $e);
        } finally {
            foreach ($temps as $temp) {
                TempFiles::remove($temp);
            }
        }

        $this->ledger->complete($reservation);
        $record = $this->records->get($postId);
        $oldPending = is_array($record['pending']) ? $record['pending'] : null;
        if ($oldPending !== null && ($oldPending['file_key'] ?? '') !== $stored->key && ($oldPending['file_key'] ?? '') !== $record['file_key']) {
            $this->store->delete((string) $oldPending['file_key']);
        }
        $record['pending'] = [
            'file_key' => $stored->key,
            'file_url' => $stored->url,
            'mime' => AudioFormat::mime($format),
            'bytes' => $stored->bytes,
            'duration' => round($duration, 2),
            'chars' => $billedTotal,
            'provider' => $provider->id(),
            'voice' => $voice['voice'],
            'model' => $voice['model'],
            'locale' => $plan->locale,
            'fingerprint' => $fingerprint,
            'content_hash' => $target,
            'render_hash' => $plan->renderHash,
            'normaliser_version' => Normaliser::version(),
            'generated_at' => $this->clock->now(),
            'chunks' => array_values($hashes),
        ];
        $record['status'] = Status::PENDING_REVIEW;
        $record['reason'] = '';
        $this->queue->dropJob($postId, $record);
        $record['reservation'] = '';
        $record['attempts'] = 0;
        $record['invalid_count'] = 0;
        $record['other_errors'] = 0;
        $record['next_attempt_at'] = 0;
        $record['last_error_code'] = '';
        $record['last_error_message'] = '';
        $record['rejected_hash'] = '';
        $this->records->save($postId, $record);
        $this->log->add('generated', $postId, ['chars' => $billedTotal, 'duration' => round($duration, 1), 'chunks' => count($chunks)]);
        do_action('read_aloud_generated', $postId, new AudioRecord($record));

        // The post may have changed while the job ran: keep this file for
        // review and queue the newer text by the usual debounce.
        $fresh = get_post($postId);
        if ($fresh instanceof \WP_Post) {
            $now = $this->planner->plan($fresh);
            if ($now->eligible && $now->contentHash !== $target) {
                $this->queue->trigger($postId, 'update');
            }
        }

        return 'done';
    }

    public static function fileKey(\WP_Post $post, string $fingerprint, string $format): string
    {
        $date = $post->post_date_gmt !== '' && $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : $post->post_date;
        $time = strtotime($date . ' UTC');
        $prefix = gmdate('Y/m', $time === false ? 0 : $time);

        return $prefix . '/' . (int) $post->ID . '-' . $fingerprint . '.' . $format;
    }

    private function stopReason(int $postId, string $jobId, ?int $deadline): ?string
    {
        if (!$this->guard->enabled()) {
            return 'stopped_kill';
        }
        if ($this->guard->isHeld()) {
            return 'held';
        }
        if ($deadline !== null && $this->clock->now() >= $deadline) {
            return 'stopped_time';
        }
        $job = $this->records->get($postId)['job'];
        if (!is_array($job) || (string) ($job['id'] ?? '') !== $jobId) {
            return 'cancelled';
        }

        return null;
    }

    private function stop(int $postId, string $why): string
    {
        $record = $this->records->get($postId);
        $record['reservation'] = '';
        if (is_array($record['job'])) {
            $record['status'] = $why === 'held' ? Status::HELD : Status::QUEUED;
            $record['job']['run_at'] = $this->clock->now();
            $record['next_attempt_at'] = $this->clock->now();
            $this->records->save($postId, $record);
            if ($why === 'stopped_time') {
                $this->dispatcher->dispatch($postId, (string) $record['job']['target_hash'], $this->clock->now(), 0);
            }
        } else {
            $record['status'] = Queue::restingStatus($record);
            $this->records->save($postId, $record);
        }
        $this->log->add('stopped', $postId, ['why' => $why]);

        return $why;
    }

    private function settleFailure(string $reservation, int $chars, string $meterReservation, ReadAloudException $e, float $started): void
    {
        // A failed call the provider did not bill releases its share.
        $this->ledger->settle($reservation, $chars, 0, $this->capPolicy->moneyFor($chars), 0);
        if ($meterReservation !== '') {
            $status = $e instanceof TransientProviderException ? $e->httpStatus() : ($e instanceof AccountException ? $e->httpStatus() : 0);
            $this->meter->record($meterReservation, 0, $e->errorCode(), $status, (int) round((microtime(true) - $started) * 1000));
            $this->meter->release($meterReservation);
        }
    }

    /**
     * Defer to the next local day at 00:05 (cap or meter).
     *
     * @param array<string, mixed> $record
     */
    private function defer(int $postId, array $record, string $reason, string $target): string
    {
        $runAt = $this->days->nextDayStart();
        $record['status'] = Status::CAPPED;
        $record['reason'] = $reason;
        $record['reservation'] = '';
        $record['next_attempt_at'] = $runAt;
        if (is_array($record['job'])) {
            $record['job']['run_at'] = $runAt;
        }
        $this->records->save($postId, $record);
        $this->dispatcher->dispatch($postId, $target, $runAt, 0);
        $this->log->add('capped', $postId, ['reason' => $reason, 'run_at' => $runAt]);

        return 'capped';
    }

    /**
     * Classify a failure and decide: hold, refuse, retry or fail.
     *
     * @param array<string, mixed> $record
     */
    private function fail(int $postId, array $record, string $target, string $code, string $message, ?SpeechProvider $provider, ?\Throwable $error = null): string
    {
        $message = Redactor::clean($message, [$this->settings->apiKey()]);
        $record['reservation'] = '';
        $record['last_error_code'] = $code;
        $record['last_error_message'] = $message;

        if ($error instanceof AccountException) {
            $this->guard->setHold($provider !== null ? $provider->id() : '', $code, $message);
            $record['status'] = Status::HELD;
            $this->records->save($postId, $record);
            do_action('read_aloud_failed', $postId, $code);
            return 'held';
        }
        if ($error instanceof RejectedTextException) {
            $this->queue->dropJob($postId, $record);
            $record['status'] = Status::REFUSED;
            $record['refused_hash'] = $target;
            $this->records->save($postId, $record);
            $this->log->add('refused', $postId, ['code' => $code]);
            do_action('read_aloud_failed', $postId, $code);
            return 'refused';
        }
        $transient = $error instanceof TransientProviderException || $error instanceof StorageException;
        if ($error instanceof InvalidAudioException) {
            $record['invalid_count'] = (int) $record['invalid_count'] + 1;
            $transient = (int) $record['invalid_count'] < 2;
        }
        if ($error instanceof UnexpectedProviderError) {
            $record['other_errors'] = (int) $record['other_errors'] + 1;
            $transient = (int) $record['other_errors'] < 2;
        }
        if ($error === null || $error instanceof ProviderConfigException || $error instanceof FormatNotJoinableException) {
            $transient = false;
        }
        $attempts = (int) $record['attempts'] + 1;
        $record['attempts'] = $attempts;
        if ($transient && $attempts <= count(self::RETRY_DELAYS)) {
            $delay = self::RETRY_DELAYS[$attempts - 1];
            $retryAfter = $error instanceof TransientProviderException ? $error->retryAfter() : null;
            if ($retryAfter !== null) {
                $delay = min(max(0, $retryAfter), self::MAX_RETRY_AFTER);
            } else {
                $delay += random_int(0, intdiv($delay, 10));
            }
            $runAt = $this->clock->now() + $delay;
            $record['status'] = Status::RETRYING;
            $record['next_attempt_at'] = $runAt;
            if (is_array($record['job'])) {
                $record['job']['run_at'] = $runAt;
            }
            $this->records->save($postId, $record);
            $this->dispatcher->dispatch($postId, $target, $runAt, 0);
            $this->log->add('retrying', $postId, ['code' => $code, 'attempt' => $attempts, 'run_at' => $runAt]);
            return 'retrying';
        }
        $this->queue->dropJob($postId, $record);
        $record['status'] = Status::FAILED;
        $record['failed_hash'] = $target;
        $this->records->save($postId, $record);
        $this->log->add('failed', $postId, ['code' => $code]);
        do_action('read_aloud_failed', $postId, $code);

        return 'failed';
    }

    /**
     * "Test voice": a fixed sample with the current settings. Counts
     * against the caps; refused while the kill switch is off.
     *
     * @return array{ok: bool, code: string, message: string, url: string}
     */
    public function testVoice(?string $locale = null): array
    {
        if (!$this->guard->enabled()) {
            return ['ok' => false, 'code' => 'disabled', 'message' => 'Generation is turned off.', 'url' => ''];
        }
        $locale ??= str_replace('_', '-', (string) get_locale());
        $voice = $this->planner->voiceFor($locale) ?? ($this->settings->voiceMap()[0] ?? null);
        if ($voice === null) {
            return ['ok' => false, 'code' => 'no_voice', 'message' => 'Add a voice to the voice map first.', 'url' => ''];
        }
        try {
            $provider = $this->provider();
        } catch (ReadAloudException $e) {
            return ['ok' => false, 'code' => $e->errorCode(), 'message' => $e->getMessage(), 'url' => ''];
        }
        $chars = $provider->countChars(self::TEST_TEXT);
        $day = $this->days->today();
        $money = $provider->isBilled() ? $this->capPolicy->moneyFor($chars) : 0;
        $reservation = $this->ledger->reserve($day, $chars, $money, $this->capPolicy->caps(), false, $this->clock->now(), 0);
        if ($reservation === null) {
            $this->capPolicy->notify($day, ['chars' => $chars, 'money' => $money]);
            return ['ok' => false, 'code' => 'capped', 'message' => 'A daily cap is reached.', 'url' => ''];
        }
        $path = null;
        try {
            $audio = $provider->synthesize(new SpeechRequest(self::TEST_TEXT, $voice['voice'], $voice['model'], $voice['locale'], $voice['format'], $voice['speed'], SpeechRequest::newRequestId(), $this->settings->int('request_timeout')));
            $path = $audio->path;
            AudioFormat::validateFile($audio->path, $voice['format'], $chars);
            $billedChars = $audio->billedChars ?? $chars;
            $this->ledger->settle($reservation, $chars, $billedChars, $money, $provider->isBilled() ? $this->capPolicy->moneyFor($billedChars) : 0);
            $this->ledger->complete($reservation);
            $stored = $this->store->put($audio->path, 'test/' . bin2hex(random_bytes(8)) . '.' . $voice['format'], AudioFormat::mime($voice['format']));
            $this->guard->clearHold('test voice');
            $this->log->add('test_voice', 0, ['chars' => $billedChars]);
            $this->capPolicy->notify($day);

            return ['ok' => true, 'code' => 'ok', 'message' => '', 'url' => $stored->url];
        } catch (ReadAloudException $e) {
            $this->ledger->settle($reservation, $chars, 0, $money, 0);
            $this->ledger->release($reservation);
            if ($e instanceof AccountException) {
                $this->guard->setHold($provider->id(), $e->errorCode(), Redactor::clean($e->getMessage(), [$this->settings->apiKey()]));
            }
            return ['ok' => false, 'code' => $e->errorCode(), 'message' => Redactor::clean($e->getMessage(), [$this->settings->apiKey()]), 'url' => ''];
        } finally {
            TempFiles::remove($path);
        }
    }
}
