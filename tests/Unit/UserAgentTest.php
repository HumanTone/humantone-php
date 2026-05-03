<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Internal\UserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentTest extends TestCase
{
    private const SDK_VERSION = '0.0.1';
    private const PHP_VERSION = '8.3.1';

    /**
     * §7.5 table verbatim. SDK 0.0.1, PHP 8.3.1.
     *
     * @return iterable<string, array{?string, string}>
     */
    public static function uaTableProvider(): iterable
    {
        yield 'null' => [null, 'humantone-php/0.0.1 (php/8.3.1)'];
        yield 'empty string' => ['', 'humantone-php/0.0.1 (php/8.3.1)'];
        yield 'whitespace only' => ['   ', 'humantone-php/0.0.1 (php/8.3.1)'];
        yield 'simple suffix' => ['my-app/1.0', 'humantone-php/0.0.1 (php/8.3.1) my-app/1.0'];
        yield 'whitespace padded suffix' => ['  my-app/1.0  ', 'humantone-php/0.0.1 (php/8.3.1) my-app/1.0'];
        yield 'crawler with comment' => [
            'crawler/2.0 (+https://example.com/bot)',
            'humantone-php/0.0.1 (php/8.3.1) crawler/2.0 (+https://example.com/bot)',
        ];
    }

    #[DataProvider('uaTableProvider')]
    public function testBuildMatchesSection75Table(?string $suffix, string $expected): void
    {
        $this->assertSame($expected, UserAgent::build(self::SDK_VERSION, self::PHP_VERSION, $suffix));
    }

    public function testDefaultUaMatchesStructuralRegexFromSection91(): void
    {
        $ua = UserAgent::build(self::SDK_VERSION, self::PHP_VERSION, null);
        $this->assertMatchesRegularExpression(
            '/^humantone-php\/\d+\.\d+\.\d+(?:-[a-zA-Z0-9.]+)? \(php\/\d+\.\d+\.\d+\)$/',
            $ua,
        );
    }

    public function testRegexAllowsSemverPreReleaseOnSdkSegmentOnly(): void
    {
        $ua = UserAgent::build('0.1.0-alpha.1', '8.3.1', null);
        $this->assertMatchesRegularExpression(
            '/^humantone-php\/\d+\.\d+\.\d+(?:-[a-zA-Z0-9.]+)? \(php\/\d+\.\d+\.\d+\)$/',
            $ua,
        );
    }

    public function testSanitizedPhpVersionIsThreeIntegerSegments(): void
    {
        $v = UserAgent::sanitizedPhpVersion();
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $v);
    }

    public function testSanitizedPhpVersionMatchesConstants(): void
    {
        $v = UserAgent::sanitizedPhpVersion();
        $expected = sprintf('%d.%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION);
        $this->assertSame($expected, $v);
    }
}
