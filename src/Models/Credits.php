<?php

declare(strict_types=1);

namespace HumanTone\Models;

final readonly class Credits
{
    public function __construct(
        public int $trial,
        public int $subscription,
        public int $extra,
        public int $total,
    ) {
    }
}
