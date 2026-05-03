<?php

declare(strict_types=1);

namespace HumanTone\Tests\Support;

use HumanTone\Internal\Sleeper;

final class RecordingSleeper implements Sleeper
{
    /** @var int[] */
    public array $calls = [];

    public function sleepMs(int $ms): void
    {
        $this->calls[] = $ms;
    }
}
