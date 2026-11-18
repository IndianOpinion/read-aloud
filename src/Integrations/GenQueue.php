<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Integrations;

/**
 * Soft integration with a job queue plugin that answers the
 * gen_queue_enqueue_job filter. Never a hard dependency.
 *
 * Contract assumed for 0.1.0 (see docs/ARCHITECTURE.md): the filter
 * receives null and a job array and returns a job id (non-empty string or
 * int) when it accepted the job; the queue runs a job by calling the
 * handler this plugin registers under its type through the
 * gen_queue_handlers filter. If GEN_QUEUE_API is defined it must equal
 * CONTRACT, otherwise the queue is treated as absent.
 */
final class GenQueue
{
    public const CONTRACT = 1;
    public const TYPE = 'read_aloud_generate';

    public function available(): bool
    {
        if (!has_filter('gen_queue_enqueue_job')) {
            return false;
        }
        if (defined('GEN_QUEUE_API') && (int) constant('GEN_QUEUE_API') !== self::CONTRACT) {
            return false;
        }

        return true;
    }

    public static function key(int $postId, string $contentHash): string
    {
        return $postId . ':' . $contentHash;
    }

    public function enqueue(int $postId, string $contentHash, int $runAt, int $estimatedChars): bool
    {
        $result = apply_filters('gen_queue_enqueue_job', null, [
            'type' => self::TYPE,
            'idempotency_key' => self::key($postId, $contentHash),
            'payload' => ['post_id' => $postId, 'content_hash' => $contentHash],
            'run_at' => $runAt,
            'cost_estimate' => ['units' => $estimatedChars, 'unit' => 'characters'],
            'caller' => 'read-aloud',
        ]);

        return (is_string($result) && $result !== '') || (is_int($result) && $result > 0);
    }

    public function cancel(int $postId, string $contentHash): void
    {
        do_action('gen_queue_cancel_job', self::key($postId, $contentHash));
    }
}
