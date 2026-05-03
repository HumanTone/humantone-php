<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Enums\HumanizationLevel;
use HumanTone\Enums\OutputFormat;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    public function testHumanizationLevelValues(): void
    {
        $this->assertSame('standard', HumanizationLevel::Standard->value);
        $this->assertSame('advanced', HumanizationLevel::Advanced->value);
        $this->assertSame('extreme', HumanizationLevel::Extreme->value);
    }

    public function testHumanizationLevelTryFromKnown(): void
    {
        $this->assertSame(HumanizationLevel::Advanced, HumanizationLevel::tryFrom('advanced'));
    }

    public function testHumanizationLevelTryFromUnknown(): void
    {
        $this->assertNull(HumanizationLevel::tryFrom('aggressive'));
    }

    public function testOutputFormatValues(): void
    {
        $this->assertSame('text', OutputFormat::Text->value);
        $this->assertSame('html', OutputFormat::Html->value);
        $this->assertSame('markdown', OutputFormat::Markdown->value);
    }

    public function testOutputFormatTryFromKnown(): void
    {
        $this->assertSame(OutputFormat::Markdown, OutputFormat::tryFrom('markdown'));
    }

    public function testOutputFormatTryFromUnknown(): void
    {
        $this->assertNull(OutputFormat::tryFrom('csv'));
    }
}
