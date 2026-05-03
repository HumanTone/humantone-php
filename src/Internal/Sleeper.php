<?php

declare(strict_types=1);

namespace HumanTone\Internal;

interface Sleeper
{
    public function sleepMs(int $ms): void;
}
