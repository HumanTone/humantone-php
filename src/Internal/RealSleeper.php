<?php

declare(strict_types=1);

namespace HumanTone\Internal;

final class RealSleeper implements Sleeper
{
    public function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
