<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Data;

/**
 * Statuses of an audio record.
 */
final class Status
{
    public const NONE = 'none';
    public const QUEUED = 'queued';
    public const GENERATING = 'generating';
    public const CAPPED = 'capped';
    public const RETRYING = 'retrying';
    public const PENDING_REVIEW = 'pending_review';
    public const APPROVED = 'approved';
    public const FAILED = 'failed';
    public const REFUSED = 'refused';
    public const SKIPPED = 'skipped';
    public const HELD = 'held';
    public const MISSING = 'missing';
    public const OUTDATED_SETTINGS = 'outdated_settings';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::NONE, self::QUEUED, self::GENERATING, self::CAPPED, self::RETRYING,
            self::PENDING_REVIEW, self::APPROVED, self::FAILED, self::REFUSED, self::SKIPPED,
            self::HELD, self::MISSING, self::OUTDATED_SETTINGS,
        ];
    }

    /**
     * Statuses that mean a job is waiting to run.
     *
     * @return list<string>
     */
    public static function waiting(): array
    {
        return [self::QUEUED, self::CAPPED, self::RETRYING, self::HELD, self::GENERATING];
    }
}
