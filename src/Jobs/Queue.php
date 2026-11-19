<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Jobs;

use IndianOpinion\ReadAloud\Clock\Clock;
use IndianOpinion\ReadAloud\Data\AudioRecord;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Support\Days;
use IndianOpinion\ReadAloud\Support\EventLog;
use IndianOpinion\ReadAloud\Text\Hashes;
use IndianOpinion\ReadAloud\Text\Normaliser;

/**
 * Change detection and queueing (PRD 4.2). Local work only.
 */
final class Queue
{
    /** Trigger reasons that come from an ordinary save. */
    public const SAVE_REASONS = ['publish', 'update', 'terms', 'setting'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Records $records,
        private readonly Planner $planner,
        private readonly Dispatcher $dispatcher,
        private readonly Ledger $ledger,
        private readonly Days $days,
        private readonly Clock $clock,
        private readonly EventLog $log
    ) {
    }

    /**
     * Evaluate a post after a trigger and queue a job when its spoken text
     * changed.
     *
     * @param array{force?: bool, over_cap?: bool, backfill?: bool, fresh?: bool} $options
     * @return string queued, unchanged, ineligible, skipped:<reason>, rejected, refused, failed, missing_post
     */
    public function trigger(int $postId, string $reason, array $options = []): string
    {
        $force = (bool) ($options['force'] ?? false);
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return 'missing_post';
        }
        if (wp_is_post_revision($post) !== false || wp_is_post_autosave($post) !== false) {
            return 'ineligible';
        }
        $record = $this->records->get($postId);
        $hadRecord = $this->records->exists($postId);
        $plan = $this->planner->plan($post);

        if (!$plan->eligible && !$plan->isSkip()) {
            if ($hadRecord) {
                $this->dropJob($postId, $record);
                $record['status'] = self::restingStatus($record);
                $record['reason'] = $plan->reason;
                $this->records->save($postId, $record);
            }
            return 'ineligible';
        }

        $record['current_hash'] = $plan->contentHash;
        $record = $this->rebaseline($post, $record, $plan);

        if ($plan->isSkip()) {
            $this->dropJob($postId, $record);
            $record['status'] = Status::SKIPPED;
            $record['reason'] = $plan->reason;
            $this->records->save($postId, $record);
            $this->log->add('skipped', $postId, ['reason' => $plan->reason]);
            return 'skipped:' . $plan->reason;
        }

        $hash = $plan->contentHash;
        $job = is_array($record['job']) ? $record['job'] : null;
        $pending = is_array($record['pending']) ? $record['pending'] : null;

        if (!$force) {
            $sameAsFile = $record['file_key'] !== '' && $record['content_hash'] === $hash;
            $sameAsJob = $job !== null && ($job['target_hash'] ?? '') === $hash;
            $sameAsPending = $pending !== null && ($pending['content_hash'] ?? '') === $hash;
            if ($sameAsFile || $sameAsJob || $sameAsPending) {
                if ($job !== null && !$sameAsJob) {
                    // The text went back to what the file already says.
                    $this->dropJob($postId, $record);
                    $record['status'] = self::restingStatus($record);
                    $record['reason'] = '';
                }
                $this->records->save($postId, $record);
                if (in_array($reason, self::SAVE_REASONS, true)) {
                    $this->ledger->incrementSavesSkipped($this->days->today());
                }
                return 'unchanged';
            }
            if ($record['rejected_hash'] === $hash) {
                $this->records->save($postId, $record);
                return 'rejected';
            }
            if ($record['refused_hash'] === $hash && $record['status'] === Status::REFUSED) {
                $this->records->save($postId, $record);
                return 'refused';
            }
            if ($record['failed_hash'] === $hash && in_array($record['status'], [Status::FAILED, Status::SKIPPED], true)) {
                $this->records->save($postId, $record);
                return 'failed';
            }
        }

        $now = $this->clock->now();
        $immediate = $force || !empty($options['backfill']) || !in_array($reason, self::SAVE_REASONS, true);
        $firstAt = $job !== null && !$immediate ? (int) ($job['first_at'] ?? $now) : $now;
        $runAt = $immediate
            ? $now
            : min($now + 60 * $this->settings->int('debounce_minutes'), $firstAt + 60 * $this->settings->int('max_wait_minutes'));
        if ($job !== null && ($job['target_hash'] ?? '') !== $hash) {
            $this->dispatcher->cancel($postId);
        }
        $record['job'] = [
            'id' => bin2hex(random_bytes(8)),
            'target_hash' => $hash,
            'first_at' => $firstAt,
            'run_at' => $runAt,
            'reason' => $reason,
            'force' => $force,
            'over_cap' => (bool) ($options['over_cap'] ?? false),
            'backfill' => (bool) ($options['backfill'] ?? false),
            'fresh' => (bool) ($options['fresh'] ?? false),
            'chars' => $plan->chars,
        ];
        $record['status'] = Status::QUEUED;
        $record['reason'] = '';
        $record['next_attempt_at'] = $runAt;
        if ($record['content_hash'] !== $hash || $force) {
            $record['attempts'] = 0;
            $record['invalid_count'] = 0;
            $record['other_errors'] = 0;
        }
        if ($force) {
            $record['rejected_hash'] = '';
            $record['refused_hash'] = '';
            $record['failed_hash'] = '';
        }
        $this->records->save($postId, $record);
        $this->dispatcher->dispatch($postId, $hash, $runAt, $plan->chars);
        $this->log->add('queued', $postId, ['reason' => $reason, 'run_at' => $runAt]);
        do_action('read_aloud_queued', $postId, $reason);

        return 'queued';
    }

    /**
     * After a normaliser upgrade, an unmodified post keeps its file: the
     * stored hash is recomputed with the new normaliser, with no synthesis.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function rebaseline(\WP_Post $post, array $record, ?Plan $plan = null): array
    {
        if ($record['file_key'] === '' || $record['normaliser_version'] === Normaliser::version()) {
            return $record;
        }
        $modified = strtotime($post->post_modified_gmt . ' UTC');
        if ($modified === false || $modified > (int) $record['generated_at']) {
            return $record;
        }
        $hash = $plan !== null && $plan->contentHash !== '' ? $plan->contentHash : Hashes::content($this->planner->spokenText($post));
        $record['content_hash'] = $hash;
        $record['normaliser_version'] = Normaliser::version();
        $record['current_hash'] = $hash;
        $this->log->add('rebaselined', (int) $post->ID, ['normaliser' => Normaliser::version()]);

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function dropJob(int $postId, array &$record): void
    {
        if (is_array($record['job'])) {
            $this->dispatcher->cancel($postId);
            $record['job'] = null;
            $record['next_attempt_at'] = 0;
        }
    }

    /**
     * Status when nothing is waiting.
     *
     * @param array<string, mixed> $record
     */
    public static function restingStatus(array $record): string
    {
        if (is_array($record['pending'])) {
            return Status::PENDING_REVIEW;
        }
        if ($record['status'] === Status::MISSING) {
            return Status::MISSING;
        }
        if ($record['file_key'] !== '' && (int) $record['approved_at'] > 0) {
            return $record['outdated'] ? Status::OUTDATED_SETTINGS : Status::APPROVED;
        }

        return Status::NONE;
    }

    /** Re-dispatch every waiting job (after resume or a cleared hold). */
    public function redispatchWaiting(): int
    {
        $count = 0;
        $now = $this->clock->now();
        foreach ($this->records->waitingIds() as $postId) {
            $record = $this->records->get($postId);
            if (!is_array($record['job'])) {
                continue;
            }
            if ($record['status'] === Status::HELD) {
                $record['status'] = Status::QUEUED;
                $record['job']['run_at'] = $now;
                $this->records->save($postId, $record);
            }
            $runAt = max($now, (int) $record['job']['run_at']);
            $this->dispatcher->dispatch($postId, (string) $record['job']['target_hash'], $runAt, 0);
            $count++;
        }

        return $count;
    }

    /** Remove queued jobs that came from a backfill. */
    public function cancelBackfill(): int
    {
        $count = 0;
        foreach ($this->records->waitingIds() as $postId) {
            $record = $this->records->get($postId);
            $job = $record['job'];
            // A running job sees its job removed and stops after the current chunk.
            if (!is_array($job) || empty($job['backfill'])) {
                continue;
            }
            $this->dropJob($postId, $record);
            $record['status'] = self::restingStatus($record);
            $this->records->save($postId, $record);
            $count++;
        }
        if ($count > 0) {
            $this->log->add('backfill_cancelled', 0, ['jobs' => $count]);
        }

        return $count;
    }

    /**
     * Find posts for a backfill.
     *
     * @param array{category?: string, after?: string, before?: string, limit?: int, outdated?: bool} $args
     * @return list<array{post: int, chars: int, reason: string}>
     */
    public function backfillCandidates(array $args): array
    {
        $limit = max(1, (int) ($args['limit'] ?? 50));
        $query = [
            'post_type' => $this->settings->strings('post_types'),
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => min(5000, $limit * 4),
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'has_password' => false,
        ];
        if (!empty($args['category'])) {
            $query['category_name'] = (string) $args['category'];
        }
        $date = [];
        if (!empty($args['after'])) {
            $date['after'] = (string) $args['after'];
        }
        if (!empty($args['before'])) {
            $date['before'] = (string) $args['before'];
        }
        if ($date !== []) {
            $query['date_query'] = [$date + ['inclusive' => true]];
        }
        $ids = get_posts($query);
        $out = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $post = get_post((int) $id);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $plan = $this->planner->plan($post);
            if (!$plan->eligible) {
                continue;
            }
            $record = new AudioRecord($this->records->get((int) $id));
            $current = $record->hasLiveFile() && !$record->isStale() && $record->contentHash === $plan->contentHash;
            if ($current && !($record->outdated && !empty($args['outdated']))) {
                continue;
            }
            if (is_array($this->records->get((int) $id)['job'])) {
                continue;
            }
            $out[] = ['post' => (int) $id, 'chars' => $plan->chars, 'reason' => $current ? 'outdated' : 'missing'];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array{category?: string, after?: string, before?: string, limit?: int, outdated?: bool, dry_run?: bool} $args
     * @return list<array{post: int, chars: int, reason: string}>
     */
    public function backfill(array $args): array
    {
        $candidates = $this->backfillCandidates($args);
        if (!empty($args['dry_run'])) {
            return $candidates;
        }
        foreach ($candidates as $candidate) {
            $this->trigger($candidate['post'], 'backfill', ['backfill' => true, 'force' => $candidate['reason'] === 'outdated']);
        }
        $this->log->add('backfill', 0, ['jobs' => count($candidates)]);

        return $candidates;
    }
}
