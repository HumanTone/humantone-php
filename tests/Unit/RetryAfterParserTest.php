<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Internal\RetryAfterParser;
use PHPUnit\Framework\TestCase;

final class RetryAfterParserTest extends TestCase
{
    public function testNullHeaderReturnsZero(): void
    {
        $this->assertSame(0, RetryAfterParser::parse(null));
    }

    public function testEmptyHeaderReturnsZero(): void
    {
        $this->assertSame(0, RetryAfterParser::parse(''));
    }

    public function testWhitespaceOnlyHeaderReturnsZero(): void
    {
        $this->assertSame(0, RetryAfterParser::parse('   '));
    }

    public function testNumericHeader(): void
    {
        $this->assertSame(120, RetryAfterParser::parse('120'));
    }

    public function testNumericHeaderWithSurroundingWhitespace(): void
    {
        $this->assertSame(120, RetryAfterParser::parse('  120  '));
    }

    public function testGarbageHeaderReturnsZero(): void
    {
        $this->assertSame(0, RetryAfterParser::parse('abc'));
    }

    public function testFutureHttpDateHeaderReturnsPositiveSeconds(): void
    {
        $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 3600);
        $result = RetryAfterParser::parse($future);
        $this->assertGreaterThan(3500, $result);
        $this->assertLessThanOrEqual(3600, $result);
    }

    public function testPastHttpDateHeaderReturnsZero(): void
    {
        $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 3600);
        $this->assertSame(0, RetryAfterParser::parse($past));
    }
}
