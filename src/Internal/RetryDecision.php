<?php

declare(strict_types=1);

namespace HumanTone\Internal;

final readonly class RetryDecision
{
    public function __construct(
        public bool $shouldRetry,
        public int $delayMs,
    ) {
    }
}
