<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Data;

/**
 * Read-only view of a post's audio record (PRD section 6.1).
 *
 * The top-level file fields describe the approved, live file. A file that
 * waits for review is in $pending.
 */
final class AudioRecord
{
    public readonly string $status;
    public readonly string $reason;
    public readonly string $fingerprint;
    public readonly string $contentHash;
    public readonly string $renderHash;
    public readonly string $normaliserVersion;
    public readonly string $fileKey;
    public readonly string $fileUrl;
    public readonly string $mime;
    public readonly int $bytes;
    public readonly float $duration;
    public readonly int $chars;
    public readonly string $provider;
    public readonly string $voice;
    public readonly string $model;
    public readonly string $locale;
    public readonly int $generatedAt;
    public readonly int $approvedBy;
    public readonly int $approvedAt;
    public readonly int $attempts;
    public readonly int $nextAttemptAt;
    public readonly string $lastErrorCode;
    public readonly string $lastErrorMessage;
    public readonly string $rejectedHash;
    /** @var array<string, mixed>|null */
    public readonly ?array $pending;
    /** @var list<array<string, mixed>> */
    public readonly array $previous;
    public readonly string $currentHash;
    public readonly bool $outdated;

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $data = Records::normalise($data);
        $this->data = $data;
        $this->status = (string) $data['status'];
        $this->reason = (string) $data['reason'];
        $this->fingerprint = (string) $data['fingerprint'];
        $this->contentHash = (string) $data['content_hash'];
        $this->renderHash = (string) $data['render_hash'];
        $this->normaliserVersion = (string) $data['normaliser_version'];
        $this->fileKey = (string) $data['file_key'];
        $this->fileUrl = (string) $data['file_url'];
        $this->mime = (string) $data['mime'];
        $this->bytes = (int) $data['bytes'];
        $this->duration = (float) $data['duration'];
        $this->chars = (int) $data['chars'];
        $this->provider = (string) $data['provider'];
        $this->voice = (string) $data['voice'];
        $this->model = (string) $data['model'];
        $this->locale = (string) $data['locale'];
        $this->generatedAt = (int) $data['generated_at'];
        $this->approvedBy = (int) $data['approved_by'];
        $this->approvedAt = (int) $data['approved_at'];
        $this->attempts = (int) $data['attempts'];
        $this->nextAttemptAt = (int) $data['next_attempt_at'];
        $this->lastErrorCode = (string) $data['last_error_code'];
        $this->lastErrorMessage = (string) $data['last_error_message'];
        $this->rejectedHash = (string) $data['rejected_hash'];
        $this->pending = is_array($data['pending']) ? $data['pending'] : null;
        $this->previous = is_array($data['previous']) ? array_values($data['previous']) : [];
        $this->currentHash = (string) $data['current_hash'];
        $this->outdated = (bool) $data['outdated'];
    }

    /** Whether an approved file exists. */
    public function hasLiveFile(): bool
    {
        return $this->fileKey !== '' && $this->approvedAt > 0;
    }

    /** Whether the live file was made from text that has since changed. */
    public function isStale(): bool
    {
        return $this->hasLiveFile() && $this->currentHash !== '' && $this->currentHash !== $this->contentHash;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
