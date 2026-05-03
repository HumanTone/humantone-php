<?php

declare(strict_types=1);

namespace HumanTone\Tests\Support;

use HumanTone\Internal\JitterSource;

final class FixedJitterSource implements JitterSource
{
    public function __construct(private readonly int $value = 0)
    {
    }

    public function jitterMs(): int
    {
        return $this->value;
    }
}
