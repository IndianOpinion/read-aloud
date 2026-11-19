<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Jobs;

use IndianOpinion\ReadAloud\Integrations\GenQueue;
use IndianOpinion\ReadAloud\Ledger\CapPolicy;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Support\Days;
use IndianOpinion\ReadAloud\Support\EventLog;

/**
 * Hands a job to the job queue plugin when present, otherwise to WP-Cron.
 */
final class Dispatcher
{
    public const CRON_HOOK = 'read_aloud_job';

    public function __construct(
        private readonly GenQueue $genQueue,
        private readonly Ledger $ledger,
        private readonly Days $days,
        private readonly EventLog $log,
        private readonly ?CapPolicy $capPolicy = null
    ) {
    }

    public function usesQueue(): bool
    {
        $available = $this->genQueue->available();
        if (!$available && $this->ledger->setFlag($this->days->today(), Ledger::FLAG_LOG_GEN_QUEUE)) {
            $this->log->add('integration_absent', 0, ['integration' => 'gen-queue', 'fallback' => 'wp-cron']);
        }

        return $available;
    }

    public function dispatch(int $postId, string $contentHash, int $runAt, int $estimatedChars): string
    {
        if ($this->usesQueue()) {
            $cost = $this->capPolicy !== null ? CapPolicy::fromMicros($this->capPolicy->moneyFor($estimatedChars)) : '0';
            if ($this->genQueue->enqueue($postId, $contentHash, $runAt, $estimatedChars, $cost)) {
                return 'queue';
            }
        }
        wp_clear_scheduled_hook(self::CRON_HOOK, [$postId]);
        wp_schedule_single_event($runAt, self::CRON_HOOK, [$postId]);

        return 'cron';
    }

    public function cancel(int $postId): void
    {
        if ($this->genQueue->available()) {
            $this->genQueue->cancel($postId);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK, [$postId]);
    }
}
