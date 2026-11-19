<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Ledger;

use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Support\Days;

/**
 * Turns settings into caps, prices characters, and fires the 80 percent
 * and reached actions once per cap per day.
 */
final class CapPolicy
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Ledger $ledger,
        private readonly Days $days
    ) {
    }

    public function caps(): Caps
    {
        $caps = [
            'chars' => $this->settings->int('cap_chars'),
            'generations' => $this->settings->int('cap_generations'),
            'money' => $this->moneyCapMicros(),
        ];
        $filtered = apply_filters('read_aloud_daily_caps', $caps);
        if (is_array($filtered)) {
            $caps = $filtered + $caps;
        }

        return new Caps(
            max(0, (int) $caps['chars']),
            max(0, (int) $caps['generations']),
            $caps['money'] === null ? null : max(0, (int) $caps['money'])
        );
    }

    /** Price per million characters in micro-units, or null when unset. */
    public function priceMicros(): ?int
    {
        $price = $this->settings->string('price_per_million');

        return $price === '' ? null : self::toMicros($price);
    }

    private function moneyCapMicros(): ?int
    {
        $cap = $this->settings->string('cap_money');
        if ($cap === '' || $this->priceMicros() === null) {
            return null;
        }

        return self::toMicros($cap);
    }

    /** Money for a number of characters, in micro-units. */
    public function moneyFor(int $chars): int
    {
        $price = $this->priceMicros();
        if ($price === null || $chars <= 0) {
            return 0;
        }

        return (int) round($chars * ($price / 1000000));
    }

    public static function toMicros(string $decimal): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', trim($decimal), $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * 1000000 + (int) str_pad($m[2] ?? '', 6, '0');
    }

    public static function fromMicros(int $micros): string
    {
        return rtrim(rtrim(sprintf('%d.%06d', intdiv($micros, 1000000), $micros % 1000000), '0'), '.');
    }

    /**
     * Fire warning and reached actions for today's usage, once per cap per
     * day. When a reservation was just refused, $attempt names what it
     * asked for, and every cap it would have passed counts as reached.
     *
     * @param array{chars: int, money: int}|null $attempt
     */
    public function notify(?string $day = null, ?array $attempt = null): void
    {
        $day ??= $this->days->today();
        $usage = $this->ledger->usage($day);
        $caps = $this->caps();
        $checks = [
            'chars' => [$caps->chars, $usage['chars'] + $usage['reserved_chars'], $attempt['chars'] ?? 0, Ledger::FLAG_WARN_CHARS, Ledger::FLAG_REACHED_CHARS],
            'generations' => [$caps->generations, $usage['generations'] + $usage['reserved_generations'], $attempt !== null ? 1 : 0, Ledger::FLAG_WARN_GENERATIONS, Ledger::FLAG_REACHED_GENERATIONS],
        ];
        if ($caps->moneyMicros !== null) {
            $checks['money'] = [$caps->moneyMicros, $usage['money'] + $usage['reserved_money'], $attempt['money'] ?? 0, Ledger::FLAG_WARN_MONEY, Ledger::FLAG_REACHED_MONEY];
        }
        foreach ($checks as $name => [$limit, $used, $asked, $warnFlag, $reachedFlag]) {
            if ($limit > 0 && $used * 5 >= $limit * 4 && $this->ledger->setFlag($day, $warnFlag)) {
                do_action('read_aloud_cap_warning', $name, $limit, $used, $day);
            }
            $reached = $limit > 0 ? $used >= $limit : $asked > 0;
            if ($attempt !== null && $asked > 0 && $used + $asked > $limit) {
                $reached = true;
            }
            if ($reached && $this->ledger->setFlag($day, $reachedFlag)) {
                do_action('read_aloud_cap_reached', $name, $limit, $used, $day);
            }
        }
    }
}
