<?php

declare(strict_types=1);

namespace HumanTone\Models;

use DateTimeImmutable;

final readonly class Subscription
{
    public function __construct(
        public bool $active,
        public ?DateTimeImmutable $expiresAt,
    ) {
    }
}
