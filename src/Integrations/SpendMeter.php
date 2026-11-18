<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Integrations;

/**
 * Soft integration with a spend meter (PRD 4.9 case 73).
 *
 * Used only when SPEND_METER_API is defined and equals CONTRACT. Both the
 * meter and the plugin's own caps must allow a call.
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

    /**
     * Ask the meter before a job.
     *
     * @return array{allow: bool, reason: string, reservation: string}
     */
    public function preSpend(string $provider, string $model, int $estimatedChars): array
    {
        $answer = apply_filters('spend_meter_pre_spend', null, [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'est_units' => $estimatedChars,
        ]);
        if ($answer instanceof \WP_Error) {
            return ['allow' => false, 'reason' => (string) $answer->get_error_code(), 'reservation' => ''];
        }
        if (is_array($answer)) {
            $allow = !array_key_exists('allow', $answer) || (bool) $answer['allow'];
            return [
                'allow' => $allow,
                'reason' => isset($answer['reason']) && is_scalar($answer['reason']) ? (string) $answer['reason'] : ($allow ? '' : 'denied'),
                'reservation' => isset($answer['reservation_id']) && is_scalar($answer['reservation_id']) ? (string) $answer['reservation_id'] : '',
            ];
        }
        if ($answer === false) {
            return ['allow' => false, 'reason' => 'denied', 'reservation' => ''];
        }

        return ['allow' => true, 'reason' => '', 'reservation' => ''];
    }

    public function record(string $reservation, int $units, string $status, int $httpStatus, int $latencyMs): void
    {
        do_action('spend_meter_record', [
            'reservation_id' => $reservation,
            'units' => $units,
            'status' => $status,
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
        ]);
    }

    public function release(string $reservation): void
    {
        do_action('spend_meter_release', ['reservation_id' => $reservation, 'caller' => self::CALLER]);
    }
}
