<?php

declare(strict_types=1);

namespace HumanTone\Models;

final readonly class DetectResult
{
    public function __construct(
        public int $aiScore,
        public ?string $requestId = null,
    ) {
    }
}
