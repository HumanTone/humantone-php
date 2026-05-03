<?php

declare(strict_types=1);

namespace HumanTone\Exceptions;

final class InvalidRequestException extends HumanToneException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
