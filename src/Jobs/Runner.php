<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Jobs;

use IndianOpinion\ReadAloud\Clock\Clock;
use IndianOpinion\ReadAloud\Control\Guard;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Support\EventLog;

/**
 * Runs jobs within the concurrency limit: from WP-Cron, from the hourly
 * sweep, from a job queue, or from "wp read-aloud run".
 */
final class Runner
{
    public const SWEEP_HOOK = 'read_aloud_sweep';

    public function __construct(
        private readonly Records $records,
        private readonly Generator $generator,
        private readonly Dispatcher $dispatcher,
        private readonly Ledger $ledger,
        private readonly Settings $settings,
        private readonly Guard $guard,
        private readonly Clock $clock,
        private readonly EventLog $log
    ) {
    }

    /**
     * Take one of the concurrency slots.
     *
     * @return array{0: string, 1: string}|null [slot name, owner]
     */
    private function slot(): ?array
    {
        $owner = bin2hex(random_bytes(16));
        $slots = max(1, min(4, $this->settings->int('concurrency')));
        for ($i = 1; $i <= $slots; $i++) {
            $name = 'slot-' . $i;
            $result = $this->ledger->lock($name, $owner, $this->clock->now(), Generator::LOCK_TTL);
            if ($result === 'busy') {
                continue;
            }
            if ($result === 'taken_over') {
                $this->log->add('lock_takeover', 0, ['lock' => $name]);
            }
            return [$name, $owner];
        }

        return null;
    }

    public function runOne(int $postId, ?int $deadline): string
    {
        $slot = $this->slot();
        if ($slot === null) {
            return 'busy';
        }
        try {
            return $this->generator->run($postId, $deadline);
        } finally {
            $this->ledger->unlock($slot[0], $slot[1]);
        }
    }

    /** WP-Cron and job-queue entry point for one post. */
    public function handle(int $postId): string
    {
        $record = $this->records->get($postId);
        if (!is_array($record['job'])) {
            return 'nothing';
        }
        // A debounced save or a retry moved the run time on; the event or
        // queue job for that later time runs it. A minute of slack covers
        // clock skew between the queue and the site.
        if ((int) ($record['job']['run_at'] ?? 0) > $this->clock->now() + 60) {
            return 'not_due';
        }
        $outcome = $this->runOne($postId, $this->clock->now() + Generator::defaultBudget());
        if ($outcome === 'busy') {
            $record = $this->records->get($postId);
            if (is_array($record['job'])) {
                $this->dispatcher->dispatch($postId, (string) $record['job']['target_hash'], $this->clock->now() + 300, 0);
            }
        }

        return $outcome;
    }

    /**
     * Process due jobs now.
     *
     * @param int $max Most jobs to run; 0 for no limit.
     * @param int|null $deadline Unix time after which no new job or chunk starts.
     * @return array<string, int> Outcome counts.
     */
    public function runDue(int $max = 0, ?int $deadline = null): array
    {
        $counts = [];
        if (!$this->guard->enabled()) {
            return ['stopped_kill' => 1];
        }
        if ($this->guard->isHeld()) {
            return ['held' => 1];
        }
        $done = 0;
        $seen = [];
        while ($max === 0 || $done < $max) {
            if ($deadline !== null && $this->clock->now() >= $deadline) {
                $counts['stopped_time'] = ($counts['stopped_time'] ?? 0) + 1;
                break;
            }
            $ids = array_values(array_diff($this->records->dueIds($this->clock->now(), 20), $seen));
            if ($ids === []) {
                break;
            }
            foreach ($ids as $postId) {
                $seen[] = $postId;
                $outcome = $this->runOne($postId, $deadline);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                $done++;
                if (in_array($outcome, ['stopped_kill', 'held', 'busy'], true)) {
                    return $counts;
                }
                if (($max !== 0 && $done >= $max) || ($deadline !== null && $this->clock->now() >= $deadline)) {
                    break 2;
                }
            }
        }

        return $counts;
    }

    /** Hourly safety net: runs a few due jobs so nothing waits for ever. */
    public function sweep(): void
    {
        $this->runDue(10, $this->clock->now() + Generator::defaultBudget());
    }
}
