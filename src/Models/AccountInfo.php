<?php

declare(strict_types=1);

namespace HumanTone\Models;

final readonly class AccountInfo
{
    public function __construct(
        public Plan $plan,
        public Credits $credits,
        public Subscription $subscription,
    ) {
    }
}
