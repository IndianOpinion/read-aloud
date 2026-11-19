<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Integrations;

/**
 * Soft integration with gen-queue (PRD 4.9 case 72). Never a hard
 * dependency.
 *
 * Follows the published gen-queue 0.x contract: the soft enqueue filter
 * `gen_queue_enqueue_job` receives null, a type, a payload and an args
 * array, and returns a job id (int) when gen-queue is active, null when it
 * is not, or a WP_Error when the enqueue failed. The handler is registered
 * with gen_queue_register_type() on the `gen_queue_register_types` action.
 * gen-queue is detected by its GEN_QUEUE_VERSION constant; a major version
 * other than 0 is treated as absent.
 */
final class GenQueue
{
    public const SUPPORTED_MAJOR = 0;
    public const TYPE = 'read-aloud.generate';

    public function available(): bool
    {
        if (!defined('GEN_QUEUE_VERSION') || !has_filter('gen_queue_enqueue_job')) {
            return false;
        }
        $version = (string) constant('GEN_QUEUE_VERSION');

        return preg_match('/^(\d+)\./', $version, $m) === 1 && (int) $m[1] === self::SUPPORTED_MAJOR;
    }

    /**
     * Idempotency key: post id, target content hash and run time. The run
     * time is part of the key because gen-queue never requeues a job that
     * succeeded under the same key, and a retry or a debounced save for the
     * same text must still get its own run.
     */
    public static function key(int $postId, string $contentHash, int $runAt): string
    {
        return $postId . ':' . $contentHash . ':' . $runAt;
    }

    /** Source label, so every queued job of one post can be cancelled together. */
    public static function source(int $postId): string
    {
        return 'read-aloud:' . $postId;
    }

    /**
     * @param string $costEstimate Estimated money as a decimal string ("0" when no price is set).
     */
    public function enqueue(int $postId, string $contentHash, int $runAt, int $estimatedChars, string $costEstimate = '0'): bool
    {
        $result = apply_filters(
            'gen_queue_enqueue_job',
            null,
            self::TYPE,
            ['post_id' => $postId, 'content_hash' => $contentHash, 'chars' => max(0, $estimatedChars)],
            [
                'idempotency_key' => self::key($postId, $contentHash, $runAt),
                'run_after' => $runAt,
                'cost_estimate' => $costEstimate,
                'source' => self::source($postId),
                'on_conflict' => 'requeue',
            ]
        );

        return is_int($result) && $result > 0;
    }

    public function cancel(int $postId): void
    {
        if (function_exists('gen_queue_cancel_where')) {
            gen_queue_cancel_where(['type' => self::TYPE, 'source' => self::source($postId)]);
        }
    }

    /**
     * Register the job handler with gen-queue. Hooked to
     * gen_queue_register_types.
     *
     * @param callable(int): string $handler Runs the job for one post id.
     */
    public static function registerType(callable $handler): void
    {
        if (!function_exists('gen_queue_register_type')) {
            return;
        }
        gen_queue_register_type(
            self::TYPE,
            static function ($job, $context = null) use ($handler): array {
                $payload = is_object($job) && isset($job->payload) && is_array($job->payload) ? $job->payload : [];

                return ['outcome' => $handler((int) ($payload['post_id'] ?? 0))];
            },
            // Retries, caps and the kill switch live in this plugin; one
            // attempt per queue job.
            ['max_attempts' => 1]
        );
    }
}
