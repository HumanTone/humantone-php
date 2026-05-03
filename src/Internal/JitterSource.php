<?php

declare(strict_types=1);

namespace HumanTone\Internal;

/**
 * Provides a single jitter value in milliseconds for retry backoff.
 *
 * Real implementation returns a uniformly random int in [-200, 200].
 * Test doubles return a fixed value for deterministic timing.
 */
interface JitterSource
{
    public function jitterMs(): int;
}
