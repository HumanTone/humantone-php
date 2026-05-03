<?php

declare(strict_types=1);

namespace HumanTone\Internal;

final class RandomJitterSource implements JitterSource
{
    public function jitterMs(): int
    {
        return random_int(-200, 200);
    }
}
