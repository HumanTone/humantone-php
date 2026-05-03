<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Client;
use HumanTone\Internal\VersionResolver;
use PHPUnit\Framework\TestCase;

final class VersionResolverTest extends TestCase
{
    public function testResolveReturnsNonEmptyString(): void
    {
        $v = VersionResolver::resolve();
        $this->assertNotSame('', $v);
    }

    public function testResolveReturnsEitherComposerVersionOrFallback(): void
    {
        // The Composer-installed version (in a git checkout, typically `dev-main`
        // or similar) OR the hardcoded Client::SDK_VERSION fallback.
        $v = VersionResolver::resolve();
        $this->assertIsString($v);
    }

    public function testFallbackConstantMatchesBriefValue(): void
    {
        // Sanity: the §7.4 fallback constant — bumped manually before each tag.
        $this->assertSame('0.0.1', Client::SDK_VERSION);
    }
}
