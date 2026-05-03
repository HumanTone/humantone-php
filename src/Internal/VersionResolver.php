<?php

declare(strict_types=1);

namespace HumanTone\Internal;

use Composer\InstalledVersions;
use HumanTone\Client;
use OutOfBoundsException;

final class VersionResolver
{
    public static function resolve(): string
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                $v = InstalledVersions::getPrettyVersion('humantone/humantone-php');
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            } catch (OutOfBoundsException) {
                // package not installed via composer in this runtime
            }
        }

        return Client::SDK_VERSION;
    }
}
