<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Review;

use IndianOpinion\ReadAloud\Clock\Clock;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Jobs\Queue;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Storage\AudioStore;
use IndianOpinion\ReadAloud\Storage\ChunkCache;
use IndianOpinion\ReadAloud\Support\EventLog;

/**
 * Approve, reject and remove (PRD 4.8 and 4.13). Callers check the
 * capability and the nonce; this class changes state.
 *
 * There is no automatic approval, now or as a setting.
 */
final class Review
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Records $records,
        private readonly Queue $queue,
        private readonly AudioStore $store,
        private readonly ChunkCache $cache,
        private readonly Clock $clock,
        private readonly EventLog $log
    ) {
    }

    /** May this user review audio for this post? */
    public static function canReview(int $postId, ?int $userId = null): bool
    {
        $capability = (string) apply_filters('read_aloud_capability', 'edit_post', 'review');
        if ($userId !== null) {
            return user_can($userId, $capability, $postId);
        }

        return current_user_can($capability, $postId);
    }

    public static function canManage(?int $userId = null): bool
    {
        $capability = (string) apply_filters('read_aloud_capability', 'manage_options', 'settings');

        return $userId !== null ? user_can($userId, $capability) : current_user_can($capability);
    }

    /**
     * @return true|\WP_Error
     */
    public function approve(int $postId, int $userId): bool|\WP_Error
    {
        $record = $this->records->get($postId);
        $pending = $record['pending'];
        if (!is_array($pending)) {
            return new \WP_Error('read_aloud_nothing_pending', 'There is no audio waiting for review.', ['status' => 409]);
        }
        $now = $this->clock->now();
        $oldKey = (string) $record['file_key'];
        $newChunks = isset($pending['chunks']) && is_array($pending['chunks']) ? array_values(array_map('strval', $pending['chunks'])) : [];
        if ($oldKey !== '' && $oldKey !== $pending['file_key']) {
            $record['previous'][] = [
                'file_key' => $oldKey,
                'delete_after' => $now + 3600 * $this->settings->int('grace_hours'),
            ];
            $oldChunks = is_array($record['chunks']) ? array_values(array_map('strval', $record['chunks'])) : [];
            $this->cache->touch($postId, array_values(array_diff($oldChunks, $newChunks)), (string) pathinfo($oldKey, PATHINFO_EXTENSION), $now);
        }
        foreach (['file_key', 'file_url', 'mime', 'bytes', 'duration', 'chars', 'provider', 'voice', 'model', 'locale', 'fingerprint', 'content_hash', 'render_hash', 'normaliser_version', 'generated_at'] as $field) {
            if (array_key_exists($field, $pending)) {
                $record[$field] = $pending[$field];
            }
        }
        $record['chunks'] = $newChunks;
        $record['pending'] = null;
        $record['approved_by'] = $userId;
        $record['approved_at'] = $now;
        $record['outdated'] = false;
        $record['status'] = is_array($record['job']) ? $record['status'] : Status::APPROVED;
        if ($record['status'] === Status::PENDING_REVIEW) {
            $record['status'] = Status::APPROVED;
        }
        $this->records->save($postId, $record);
        $this->log->add('approved', $postId, ['user' => $userId]);
        do_action('read_aloud_approved', $postId, $userId);
        $this->playerChanged($postId);

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    public function reject(int $postId, int $userId): bool|\WP_Error
    {
        $record = $this->records->get($postId);
        $pending = $record['pending'];
        if (!is_array($pending)) {
            return new \WP_Error('read_aloud_nothing_pending', 'There is no audio waiting for review.', ['status' => 409]);
        }
        $key = (string) ($pending['file_key'] ?? '');
        if ($key !== '' && $key !== $record['file_key']) {
            $this->store->delete($key);
        }
        $record['rejected_hash'] = (string) ($pending['content_hash'] ?? '');
        $record['pending'] = null;
        if (!is_array($record['job'])) {
            $record['status'] = Queue::restingStatus($record);
        }
        $this->records->save($postId, $record);
        $this->log->add('rejected', $postId, ['user' => $userId]);
        do_action('read_aloud_rejected', $postId, $userId);
        $this->playerChanged($postId);

        return true;
    }

    /** Delete every file and the record, and turn audio off for the post. */
    public function remove(int $postId, int $userId = 0): void
    {
        $record = $this->records->get($postId);
        $this->queue->dropJob($postId, $record);
        foreach ($this->keys($record) as $key) {
            $this->store->delete($key);
        }
        $this->cache->deletePost($postId);
        $this->records->delete($postId);
        $this->records->setMode($postId, Records::MODE_OFF);
        $this->log->add('removed', $postId, ['user' => $userId]);
        $this->playerChanged($postId);
    }

    /** Permanent deletion of the post: files, chunks and record go. */
    public function purge(int $postId): void
    {
        if (!$this->records->exists($postId)) {
            $this->cache->deletePost($postId);
            return;
        }
        $record = $this->records->get($postId);
        $this->queue->dropJob($postId, $record);
        foreach ($this->keys($record) as $key) {
            $this->store->delete($key);
        }
        $this->cache->deletePost($postId);
        $this->records->delete($postId);
        delete_post_meta($postId, Records::META_MODE);
        $this->log->add('purged', $postId);
    }

    /**
     * @param array<string, mixed> $record
     * @return list<string>
     */
    private function keys(array $record): array
    {
        $keys = [];
        if ($record['file_key'] !== '') {
            $keys[] = (string) $record['file_key'];
        }
        if (is_array($record['pending']) && !empty($record['pending']['file_key'])) {
            $keys[] = (string) $record['pending']['file_key'];
        }
        foreach (is_array($record['previous']) ? $record['previous'] : [] as $previous) {
            if (is_array($previous) && !empty($previous['file_key'])) {
                $keys[] = (string) $previous['file_key'];
            }
        }

        return array_values(array_unique($keys));
    }

    public function playerChanged(int $postId): void
    {
        clean_post_cache($postId);
        do_action('read_aloud_player_changed', $postId);
    }
}
