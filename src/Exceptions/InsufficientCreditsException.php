<?php

declare(strict_types=1);

namespace HumanTone\Exceptions;

use Throwable;

final class InsufficientCreditsException extends HumanToneException
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
        private readonly ?int $requiredCredits = null,
        private readonly ?int $availableCredits = null,
    ) {
        parent::__construct($message, $statusCode, $requestId, $errorCode, $details, $previous);
    }

    public function getRequiredCredits(): ?int
    {
        return $this->requiredCredits;
    }

    public function getAvailableCredits(): ?int
    {
        return $this->availableCredits;
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
