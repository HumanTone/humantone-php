<?php

declare(strict_types=1);

namespace HumanTone\Exceptions;

use RuntimeException;
use Throwable;

abstract class HumanToneException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $requestId = null,
        private readonly ?string $errorCode = null,
        private readonly ?array $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    abstract public function isRetryable(): bool;
}
