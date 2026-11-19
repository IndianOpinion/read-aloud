<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud;

use IndianOpinion\ReadAloud\Clock\Clock;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Jobs\Planner;
use IndianOpinion\ReadAloud\Jobs\Queue;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Review\Review;
use IndianOpinion\ReadAloud\Storage\AudioStore;
use IndianOpinion\ReadAloud\Storage\ChunkCache;
use IndianOpinion\ReadAloud\Storage\UploadsStore;
use IndianOpinion\ReadAloud\Support\Days;
use IndianOpinion\ReadAloud\Support\EventLog;
use IndianOpinion\ReadAloud\Text\Hashes;
use IndianOpinion\ReadAloud\Text\Normaliser;

/**
 * The daily maintenance event and the batch sweeps behind settings changes
 * and normaliser upgrades. Each run touches at most BATCH records and
 * continues on the next run.
 */
final class Maintenance
{
    public const DAILY_HOOK = 'read_aloud_daily';
    public const OUTDATED_HOOK = 'read_aloud_mark_outdated';
    public const BATCH = 500;
    public const CHUNK_RETENTION = 7 * 86400;
    public const SAMPLE = 20;
    public const OPTION_CURSOR = 'read_aloud_maintenance_cursor';
    public const OPTION_NORMALISER = 'read_aloud_normaliser_seen';

    public function __construct(
        private readonly Records $records,
        private readonly Planner $planner,
        private readonly Queue $queue,
        private readonly Review $review,
        private readonly AudioStore $store,
        private readonly ChunkCache $cache,
        private readonly Ledger $ledger,
        private readonly Days $days,
        private readonly Clock $clock,
        private readonly EventLog $log
    ) {
    }

    /** @return array<string, int> */
    public function daily(): array
    {
        $now = $this->clock->now();
        $stats = ['deleted_files' => 0, 'pruned_chunks' => 0, 'released' => 0, 'missing' => 0, 'trimmed_days' => 0];
        $cursor = (int) get_option(self::OPTION_CURSOR, 0);
        $ids = $this->records->allIds(self::BATCH, $cursor);
        foreach ($ids as $postId) {
            $stats['deleted_files'] += $this->deleteExpired($postId, $now);
            $record = $this->records->get($postId);
            $keep = is_array($record['chunks']) ? array_map('strval', $record['chunks']) : [];
            if (is_array($record['pending']) && isset($record['pending']['chunks']) && is_array($record['pending']['chunks'])) {
                $keep = array_merge($keep, array_map('strval', $record['pending']['chunks']));
            }
            $stats['pruned_chunks'] += $this->cache->prune($postId, array_values($keep), $now - self::CHUNK_RETENTION);
        }
        update_option(self::OPTION_CURSOR, count($ids) < self::BATCH ? 0 : $cursor + self::BATCH, false);
        foreach ($this->cache->postIds() as $postId) {
            if (!$this->records->exists($postId)) {
                $stats['pruned_chunks'] += $this->cache->prune($postId, [], $now - self::CHUNK_RETENTION);
            }
        }

        $stats['released'] = $this->ledger->releaseStale($now - 3600);
        if ($stats['released'] > 0) {
            $this->log->add('reservations_released', 0, ['count' => $stats['released']]);
        }
        $stats['missing'] = $this->checkSample();
        $stats['trimmed_days'] = $this->ledger->trim($this->days->daysAgo(29));
        $this->deleteOldTestFiles($now);
        $this->rebaseline();

        return $stats;
    }

    private function deleteExpired(int $postId, int $now): int
    {
        $record = $this->records->get($postId);
        if (!is_array($record['previous']) || $record['previous'] === []) {
            return 0;
        }
        $kept = [];
        $deleted = 0;
        foreach ($record['previous'] as $previous) {
            if (!is_array($previous)) {
                continue;
            }
            if ((int) ($previous['delete_after'] ?? 0) <= $now) {
                $key = (string) ($previous['file_key'] ?? '');
                if ($key !== '' && $key !== $record['file_key'] && $this->store->delete($key)) {
                    $deleted++;
                }
                continue;
            }
            $kept[] = $previous;
        }
        $record['previous'] = $kept;
        $this->records->save($postId, $record);
        if ($deleted > 0) {
            $this->log->add('old_files_deleted', $postId, ['files' => $deleted]);
        }

        return $deleted;
    }

    /** Check a small random sample of live files. */
    private function checkSample(): int
    {
        $ids = $this->records->liveIds(self::BATCH);
        if ($ids === []) {
            return 0;
        }
        shuffle($ids);

        return count($this->verify(array_slice($ids, 0, self::SAMPLE), false));
    }

    /**
     * Mark records whose file is gone as missing.
     *
     * @param list<int>|null $ids Null checks every live record.
     * @return list<int> Post ids found missing.
     */
    public function verify(?array $ids = null, bool $requeue = false): array
    {
        $missing = [];
        if ($ids === null) {
            $ids = [];
            $offset = 0;
            do {
                $batch = $this->records->liveIds(self::BATCH, $offset);
                $ids = array_merge($ids, $batch);
                $offset += self::BATCH;
            } while (count($batch) === self::BATCH);
            $ids = array_merge($ids, $this->records->idsByStatus([Status::MISSING], -1));
        }
        foreach (array_unique($ids) as $postId) {
            $record = $this->records->get($postId);
            if ($record['file_key'] === '') {
                continue;
            }
            if ($record['status'] !== Status::MISSING && $this->store->exists((string) $record['file_key'])) {
                continue;
            }
            if ($record['status'] === Status::MISSING && $this->store->exists((string) $record['file_key'])) {
                $record['status'] = Queue::restingStatus(['status' => Status::NONE] + $record);
                $this->records->save($postId, $record);
                $this->review->playerChanged($postId);
                continue;
            }
            $missing[] = $postId;
            if ($record['status'] !== Status::MISSING) {
                $record['status'] = Status::MISSING;
                $this->records->save($postId, $record);
                $this->log->add('missing', $postId, ['file' => (string) $record['file_key']]);
                $this->review->playerChanged($postId);
            }
            if ($requeue) {
                $this->queue->trigger($postId, 'verify', ['force' => true]);
            }
        }

        return $missing;
    }

    /**
     * Files in the audio directory that no record points to.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        if (!$this->store instanceof UploadsStore) {
            return [];
        }
        $referenced = [];
        $offset = 0;
        do {
            $batch = $this->records->allIds(self::BATCH, $offset);
            foreach ($batch as $postId) {
                $record = $this->records->get($postId);
                $referenced[(string) $record['file_key']] = true;
                if (is_array($record['pending'])) {
                    $referenced[(string) ($record['pending']['file_key'] ?? '')] = true;
                }
                foreach (is_array($record['previous']) ? $record['previous'] : [] as $previous) {
                    if (is_array($previous)) {
                        $referenced[(string) ($previous['file_key'] ?? '')] = true;
                    }
                }
            }
            $offset += self::BATCH;
        } while (count($batch) === self::BATCH);
        $out = [];
        foreach ($this->store->keys() as $key) {
            if (!isset($referenced[$key]) && !str_starts_with($key, 'test/')) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<string> Keys deleted (or that would be, with $dryRun).
     */
    public function prune(bool $dryRun): array
    {
        $orphans = $this->orphans();
        if (!$dryRun) {
            foreach ($orphans as $key) {
                $this->store->delete($key);
            }
            if ($orphans !== []) {
                $this->log->add('pruned', 0, ['files' => count($orphans)]);
            }
        }

        return $orphans;
    }

    private function deleteOldTestFiles(int $now): void
    {
        if (!$this->store instanceof UploadsStore) {
            return;
        }
        $files = glob($this->store->baseDir() . '/test/*');
        foreach ($files === false ? [] : $files as $file) {
            $mtime = filemtime($file);
            if (is_file($file) && $mtime !== false && $mtime < $now - 86400) {
                unlink($file);
            }
        }
    }

    /**
     * After a settings change, mark live files whose render hash no longer
     * matches as outdated_settings (and clear the mark when it matches
     * again). Never queues anything.
     *
     * @return int Records changed in this batch.
     */
    public function markOutdated(int $offset = 0): int
    {
        $ids = $this->records->liveIds(self::BATCH, $offset);
        $changed = 0;
        foreach ($ids as $postId) {
            $post = get_post($postId);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $record = $this->records->get($postId);
            $locale = $this->planner->locale($post);
            $voice = $this->planner->voiceFor($locale);
            $render = $voice === null ? '' : Hashes::render($this->planner->renderSettings($voice, $locale));
            $outdated = $render !== $record['render_hash'];
            if ($outdated === (bool) $record['outdated']) {
                continue;
            }
            $record['outdated'] = $outdated;
            if (in_array($record['status'], [Status::APPROVED, Status::OUTDATED_SETTINGS], true)) {
                $record['status'] = $outdated ? Status::OUTDATED_SETTINGS : Status::APPROVED;
            }
            $this->records->save($postId, $record);
            $changed++;
        }
        if (count($ids) === self::BATCH) {
            wp_schedule_single_event($this->clock->now() + 60, self::OUTDATED_HOOK, [$offset + self::BATCH]);
        }
        if ($changed > 0) {
            $this->log->add('outdated_marked', 0, ['records' => $changed]);
        }

        return $changed;
    }

    /**
     * Re-baseline unmodified posts after a normaliser upgrade, with no
     * synthesis. Runs in batches until every live record is done.
     */
    public function rebaseline(): int
    {
        if (get_option(self::OPTION_NORMALISER, '') === Normaliser::version()) {
            return 0;
        }
        $changed = 0;
        $offset = 0;
        $processed = 0;
        do {
            $batch = $this->records->liveIds(self::BATCH, $offset);
            foreach ($batch as $postId) {
                $post = get_post($postId);
                if (!$post instanceof \WP_Post) {
                    continue;
                }
                $record = $this->records->get($postId);
                $updated = $this->queue->rebaseline($post, $record);
                if ($updated !== $record) {
                    $this->records->save($postId, $updated);
                    $changed++;
                }
                $processed++;
            }
            $offset += self::BATCH;
        } while (count($batch) === self::BATCH && $processed < self::BATCH * 4);
        if (count($batch) < self::BATCH) {
            update_option(self::OPTION_NORMALISER, Normaliser::version(), false);
        }

        return $changed;
    }
}
