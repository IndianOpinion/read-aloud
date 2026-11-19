<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Data;

/**
 * Stores audio records and the per-post setting in post meta.
 *
 * The record is one JSON meta entry. Three small mirror entries hold the
 * status, the next run time of a waiting job, and whether a live file
 * exists, so the status screen and the runner can query them without
 * decoding every record.
 */
final class Records
{
    public const META = '_read_aloud';
    public const META_MODE = '_read_aloud_mode';
    public const META_STATUS = '_read_aloud_status';
    public const META_DUE = '_read_aloud_due';
    public const META_LIVE = '_read_aloud_live';

    public const MODE_INHERIT = 'inherit';
    public const MODE_ON = 'on';
    public const MODE_OFF = 'off';

    /** Every post status a record may sit under, trash included. */
    public const ANY_STATUS = ['publish', 'future', 'draft', 'pending', 'private', 'trash', 'inherit'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'status' => Status::NONE,
            'reason' => '',
            'fingerprint' => '',
            'content_hash' => '',
            'render_hash' => '',
            'normaliser_version' => '',
            'file_key' => '',
            'file_url' => '',
            'mime' => '',
            'bytes' => 0,
            'duration' => 0.0,
            'chars' => 0,
            'provider' => '',
            'voice' => '',
            'model' => '',
            'locale' => '',
            'generated_at' => 0,
            'approved_by' => 0,
            'approved_at' => 0,
            'attempts' => 0,
            'next_attempt_at' => 0,
            'last_error_code' => '',
            'last_error_message' => '',
            'rejected_hash' => '',
            'pending' => null,
            'previous' => [],
            'current_hash' => '',
            'outdated' => false,
            'job' => null,
            'chunks' => [],
            'failed_hash' => '',
            'refused_hash' => '',
            'invalid_count' => 0,
            'other_errors' => 0,
            'reservation' => '',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        return array_intersect_key($data, self::defaults()) + self::defaults();
    }

    /** @return array<string, mixed> */
    public function get(int $postId): array
    {
        $raw = get_post_meta($postId, self::META, true);
        if (!is_string($raw) || $raw === '') {
            return self::defaults();
        }
        $data = json_decode($raw, true);

        return is_array($data) ? self::normalise($data) : self::defaults();
    }

    public function exists(int $postId): bool
    {
        $raw = get_post_meta($postId, self::META, true);

        return is_string($raw) && $raw !== '';
    }

    public function record(int $postId): ?AudioRecord
    {
        return $this->exists($postId) ? new AudioRecord($this->get($postId)) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(int $postId, array $data): void
    {
        $data = self::normalise($data);
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        update_post_meta($postId, self::META, wp_slash($json));
        update_post_meta($postId, self::META_STATUS, (string) $data['status']);
        if (is_array($data['job']) && isset($data['job']['run_at'])) {
            update_post_meta($postId, self::META_DUE, (string) (int) $data['job']['run_at']);
        } else {
            delete_post_meta($postId, self::META_DUE);
        }
        if ($data['file_key'] !== '' && (int) $data['approved_at'] > 0) {
            update_post_meta($postId, self::META_LIVE, '1');
        } else {
            delete_post_meta($postId, self::META_LIVE);
        }
    }

    public function delete(int $postId): void
    {
        foreach ([self::META, self::META_STATUS, self::META_DUE, self::META_LIVE] as $key) {
            delete_post_meta($postId, $key);
        }
    }

    public function mode(int $postId): string
    {
        $mode = get_post_meta($postId, self::META_MODE, true);

        return in_array($mode, [self::MODE_ON, self::MODE_OFF], true) ? (string) $mode : self::MODE_INHERIT;
    }

    public function setMode(int $postId, string $mode): void
    {
        if ($mode === self::MODE_INHERIT) {
            delete_post_meta($postId, self::META_MODE);
            return;
        }
        if (!in_array($mode, [self::MODE_ON, self::MODE_OFF], true)) {
            throw new \InvalidArgumentException('Mode must be inherit, on or off.');
        }
        update_post_meta($postId, self::META_MODE, $mode);
    }

    /**
     * @param array<string, mixed> $extra
     * @return list<int>
     */
    private function query(array $extra, int $limit, int $offset = 0): array
    {
        $ids = get_posts($extra + [
            'post_type' => 'any',
            'post_status' => self::ANY_STATUS,
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        return array_values(array_map(static fn ($id): int => $id instanceof \WP_Post ? (int) $id->ID : (int) $id, is_array($ids) ? $ids : []));
    }

    /**
     * @param list<string> $statuses
     * @return list<int>
     */
    public function idsByStatus(array $statuses, int $limit = 500, int $offset = 0): array
    {
        return $this->query([
            'meta_query' => [['key' => self::META_STATUS, 'value' => $statuses, 'compare' => 'IN']],
        ], $limit, $offset);
    }

    /** @return list<int> */
    public function dueIds(int $now, int $limit = 50): array
    {
        return $this->query([
            'meta_query' => [['key' => self::META_DUE, 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC']],
            'orderby' => 'meta_value_num',
            'meta_key' => self::META_DUE,
        ], $limit);
    }

    /** @return list<int> */
    public function waitingIds(int $limit = 5000): array
    {
        return $this->query([
            'meta_query' => [['key' => self::META_DUE, 'compare' => 'EXISTS']],
        ], $limit);
    }

    /** @return list<int> */
    public function liveIds(int $limit = 500, int $offset = 0): array
    {
        return $this->query([
            'meta_query' => [['key' => self::META_LIVE, 'compare' => 'EXISTS']],
        ], $limit, $offset);
    }

    /** @return list<int> */
    public function allIds(int $limit = 500, int $offset = 0): array
    {
        return $this->query([
            'meta_query' => [['key' => self::META, 'compare' => 'EXISTS']],
        ], $limit, $offset);
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        $out = [];
        foreach (Status::all() as $status) {
            $out[$status] = count($this->idsByStatus([$status], -1));
        }

        return $out;
    }
}
