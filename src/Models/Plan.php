<?php

declare(strict_types=1);

namespace HumanTone\Models;

final readonly class Plan
{
    public function __construct(
        public string $id,
        public string $name,
        public int $maxWords,
        public int $monthlyCredits,
        public bool $apiAccess,
    ) {
    }
}
