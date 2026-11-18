<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Ledger;

/**
 * Daily limits. A money cap of null means the money cap is off.
 */
final class Caps
{
    public function __construct(
        public readonly int $chars,
        public readonly int $generations,
        public readonly ?int $moneyMicros = null
    ) {
    }

    /** @return array{chars: int, generations: int, money: int|null} */
    public function toArray(): array
    {
        return ['chars' => $this->chars, 'generations' => $this->generations, 'money' => $this->moneyMicros];
    }
}
