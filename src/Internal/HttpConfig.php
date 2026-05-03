<?php

declare(strict_types=1);

namespace HumanTone\Internal;

final readonly class HttpConfig
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public string $userAgent,
        public float $timeout,
        public int $maxRetries,
        public bool $retryOnPost,
    ) {
    }
}
