<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Control;

use IndianOpinion\ReadAloud\Clock\Clock;
use IndianOpinion\ReadAloud\Support\EventLog;
use IndianOpinion\ReadAloud\Support\FreshOptions;

/**
 * The kill switch and the site-wide account hold.
 *
 * Both are read fresh from the database at every decision point.
 */
final class Guard
{
    public const OPTION_ENABLED = 'read_aloud_enabled';
    public const OPTION_HOLD = 'read_aloud_hold';

    public function __construct(private readonly Clock $clock, private readonly EventLog $log)
    {
    }

    public function constantDisabled(): bool
    {
        return defined('READ_ALOUD_DISABLED') && (bool) constant('READ_ALOUD_DISABLED');
    }

    /** The setting alone, fresh from the database. */
    public function settingEnabled(): bool
    {
        return FreshOptions::get(self::OPTION_ENABLED, '1') !== '0';
    }

    /**
     * Whether generation may run now. The constant wins; the filter can
     * turn generation off but never on.
     */
    public function enabled(): bool
    {
        if ($this->constantDisabled()) {
            return false;
        }
        $enabled = $this->settingEnabled();
        if (!$enabled) {
            return false;
        }

        return (bool) apply_filters('read_aloud_enabled', true);
    }

    public function setEnabled(bool $enabled, string $actor): void
    {
        $old = $this->settingEnabled();
        update_option(self::OPTION_ENABLED, $enabled ? '1' : '0', false);
        if ($old !== $enabled) {
            $this->log->add('kill_switch', 0, ['enabled' => $enabled, 'actor' => $actor]);
            do_action('read_aloud_kill_switch_changed', $old, $enabled, $actor);
        }
    }

    /**
     * @return array{provider: string, code: string, message: string, at: int}|null
     */
    public function hold(): ?array
    {
        $hold = FreshOptions::get(self::OPTION_HOLD, '');
        if (!is_array($hold) || !isset($hold['code'])) {
            return null;
        }

        return [
            'provider' => (string) ($hold['provider'] ?? ''),
            'code' => (string) $hold['code'],
            'message' => (string) ($hold['message'] ?? ''),
            'at' => (int) ($hold['at'] ?? 0),
        ];
    }

    public function isHeld(): bool
    {
        return $this->hold() !== null;
    }

    public function setHold(string $provider, string $code, string $message): void
    {
        update_option(self::OPTION_HOLD, [
            'provider' => $provider,
            'code' => $code,
            'message' => $message,
            'at' => $this->clock->now(),
        ], false);
        $this->log->add('held', 0, ['provider' => $provider, 'code' => $code]);
        do_action('read_aloud_account_hold', $provider, $code);
    }

    public function clearHold(string $actor): bool
    {
        if (!$this->isHeld()) {
            return false;
        }
        delete_option(self::OPTION_HOLD);
        $this->log->add('hold_cleared', 0, ['actor' => $actor]);

        return true;
    }
}
