<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Integrations;

/**
 * Soft integration with spend-meter (PRD 4.9 case 73), contract version 1.
 *
 * Used only when SPEND_METER_API is defined and equals CONTRACT. The calls
 * follow the published contract: `spend_meter_pre_spend` is a filter that
 * receives null and an intent array and returns null (no meter) or a
 * decision array; a call is allowed only when `allowed` is exactly true.
 * Every reservation is followed by `spend_meter_record` (a call happened)
 * or `spend_meter_release` (no call was made). Both the meter and the
 * plugin's own caps must allow a call.
 */
final class SpendMeter
{
    public const CONTRACT = 1;
    public const PURPOSE = 'read-aloud';
    public const CALLER = 'read-aloud';

    /** "present", "absent" or "version". */
    public function state(): string
    {
        if (!defined('SPEND_METER_API')) {
            return 'absent';
        }

        return (int) constant('SPEND_METER_API') === self::CONTRACT ? 'present' : 'version';
    }

    public function available(): bool
    {
        return $this->state() === 'present';
    }

    /** Provider slug in the meter's form (lower case). */
    public static function providerSlug(string $provider): string
    {
        $slug = strtolower($provider);

        return $slug !== '' ? $slug : 'unknown';
    }

    /** Model id in the meter's form (at most 128 characters). */
    public static function modelId(string $model): string
    {
        $model = $model !== '' ? $model : 'unknown';

        return function_exists('mb_substr') ? mb_substr($model, 0, 128, 'UTF-8') : substr($model, 0, 128);
    }

    /**
     * Ask the meter before spending.
     *
     * @return array{present: bool, allow: bool, reason: string, reservation: string}
     *         present is false when the filter returned null (no meter answered).
     */
    public function preSpend(string $provider, string $model, int $estimatedChars): array
    {
        $intent = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => self::providerSlug($provider),
            'model' => self::modelId($model),
            'est_units' => max(0, $estimatedChars),
        ];
        $decision = apply_filters('spend_meter_pre_spend', null, $intent);
        if ($decision === null) {
            return ['present' => false, 'allow' => true, 'reason' => '', 'reservation' => ''];
        }
        if (!is_array($decision)) {
            // Anything but a decision array is a deny: never spend on an
            // answer we cannot read.
            return ['present' => true, 'allow' => false, 'reason' => 'invalid_decision', 'reservation' => ''];
        }
        $allowed = ($decision['allowed'] ?? null) === true;
        $reason = isset($decision['reason']) && is_string($decision['reason']) && $decision['reason'] !== ''
            ? $decision['reason']
            : ($allowed ? 'ok' : 'denied');
        $reservation = isset($decision['reservation_id']) && is_string($decision['reservation_id']) ? $decision['reservation_id'] : '';

        return [
            'present' => true,
            'allow' => $allowed,
            'reason' => $reason,
            'reservation' => $allowed ? $reservation : '',
        ];
    }

    /**
     * Record one provider call against a reservation.
     *
     * @param string $status ok, error_billed or error_unbilled
     */
    public function record(
        string $reservation,
        string $provider,
        string $model,
        int $units,
        string $status,
        int $httpStatus,
        int $latencyMs,
        string $errorCode = ''
    ): void {
        $call = [
            'reservation_id' => $reservation !== '' ? $reservation : null,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => self::providerSlug($provider),
            'model' => self::modelId($model),
            'units' => max(0, $units),
            'status' => in_array($status, ['ok', 'error_billed', 'error_unbilled'], true) ? $status : 'error_unbilled',
            'latency_ms' => max(0, $latencyMs),
        ];
        if ($httpStatus > 0) {
            $call['http_status'] = $httpStatus;
        }
        if ($errorCode !== '') {
            $call['error_code'] = substr($errorCode, 0, 64);
        }
        do_action('spend_meter_record', $call);
    }

    /** Give up a reservation no call was made against. */
    public function release(string $reservation): void
    {
        if ($reservation !== '') {
            do_action('spend_meter_release', $reservation);
        }
    }
}
