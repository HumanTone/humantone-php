<?php

declare(strict_types=1);

namespace HumanTone\Internal;

final class RetryAfterParser
{
    /**
     * Parses the Retry-After header per RFC 7231.
     *
     * Accepts either delta-seconds ("120") or HTTP-date
     * ("Wed, 21 Oct 2026 07:28:00 GMT"). Returns 0 on malformed input.
     */
    public static function parse(?string $header): int
    {
        if ($header === null) {
            return 0;
        }
        $header = trim($header);
        if ($header === '') {
            return 0;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }
        $ts = strtotime($header);
        return $ts !== false ? max(0, $ts - time()) : 0;
    }
}
