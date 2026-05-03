<?php

declare(strict_types=1);

namespace HumanTone\Internal;

final class UserAgent
{
    public static function build(string $sdkVersion, string $phpVersion, ?string $userSuffix): string
    {
        $base = sprintf('humantone-php/%s (php/%s)', $sdkVersion, $phpVersion);

        $trimmed = trim($userSuffix ?? '');
        if ($trimmed === '') {
            return $base;
        }

        return $base . ' ' . $trimmed;
    }

    public static function sanitizedPhpVersion(): string
    {
        return sprintf('%d.%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION);
    }
}
