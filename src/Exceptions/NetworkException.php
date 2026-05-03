<?php

declare(strict_types=1);

namespace HumanTone\Exceptions;

final class NetworkException extends HumanToneException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
