<?php

declare(strict_types=1);

namespace HumanTone\Exceptions;

use Throwable;

final class RateLimitException extends HumanToneException
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        string $message,
        ?int $statusCode = null,
        ?string $requestId = null,
        ?string $errorCode = null,
        ?array $details = null,
        ?Throwable $previous = null,
        private readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message, $statusCode, $requestId, $errorCode, $details, $previous);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
