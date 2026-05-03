<?php

declare(strict_types=1);

namespace HumanTone\Models;

use HumanTone\Enums\OutputFormat;

final readonly class HumanizeResult
{
    public function __construct(
        public string $text,
        public OutputFormat $outputFormat,
        public int $creditsUsed,
        public ?string $requestId,
    ) {
    }
}
