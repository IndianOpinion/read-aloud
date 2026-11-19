<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

final class Voice
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $locale,
        public readonly ?string $gender = null
    ) {
    }
}
