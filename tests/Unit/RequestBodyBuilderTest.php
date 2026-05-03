<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Enums\HumanizationLevel;
use HumanTone\Enums\OutputFormat;
use HumanTone\Internal\RequestBodyBuilder;
use PHPUnit\Framework\TestCase;

final class RequestBodyBuilderTest extends TestCase
{
    public function testHumanizeDefaultsSendsTextAsContentAndStandardLevelAndTextFormat(): void
    {
        $body = RequestBodyBuilder::humanize(
            text: 'sample',
            level: HumanizationLevel::Standard,
            outputFormat: OutputFormat::Text,
            customInstructions: null,
        );

        $this->assertSame('sample', $body['content']);
        $this->assertSame('standard', $body['humanization_level']);
        $this->assertSame('text', $body['output_format']);
        $this->assertArrayNotHasKey('custom_instructions', $body);
    }

    public function testHumanizeJsonShapeMatchesBriefDefaults(): void
    {
        // §14 acceptance: "Default outputFormat: Text verified in test that asserts request body".
        $body = RequestBodyBuilder::humanize('x', HumanizationLevel::Standard, OutputFormat::Text, null);
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertJsonStringEqualsJsonString(
            '{"content":"x","humanization_level":"standard","output_format":"text"}',
            $json,
        );
    }

    public function testHumanizeUsesContentNotTextOnTheWire(): void
    {
        // The SDK takes a `text` parameter but the API field is `content`.
        $body = RequestBodyBuilder::humanize('y', HumanizationLevel::Advanced, OutputFormat::Html, null);
        $this->assertArrayHasKey('content', $body);
        $this->assertArrayNotHasKey('text', $body);
    }

    public function testHumanizeIncludesCustomInstructionsWhenProvided(): void
    {
        $body = RequestBodyBuilder::humanize(
            'x',
            HumanizationLevel::Standard,
            OutputFormat::Text,
            customInstructions: 'Keep tone formal',
        );
        $this->assertSame('Keep tone formal', $body['custom_instructions']);
    }

    public function testHumanizeOmitsCustomInstructionsWhenNull(): void
    {
        $body = RequestBodyBuilder::humanize('x', HumanizationLevel::Standard, OutputFormat::Text, null);
        $this->assertArrayNotHasKey('custom_instructions', $body);
    }

    public function testHumanizeSendsEmptyStringCustomInstructionsIfProvided(): void
    {
        // Brief doesn't forbid empty strings; only null omits the field.
        $body = RequestBodyBuilder::humanize('x', HumanizationLevel::Standard, OutputFormat::Text, '');
        $this->assertSame('', $body['custom_instructions']);
    }

    public function testHumanizeAllEnumLevelsSerializeCorrectly(): void
    {
        foreach (HumanizationLevel::cases() as $level) {
            $body = RequestBodyBuilder::humanize('x', $level, OutputFormat::Text, null);
            $this->assertSame($level->value, $body['humanization_level']);
        }
    }

    public function testHumanizeAllOutputFormatsSerializeCorrectly(): void
    {
        foreach (OutputFormat::cases() as $fmt) {
            $body = RequestBodyBuilder::humanize('x', HumanizationLevel::Standard, $fmt, null);
            $this->assertSame($fmt->value, $body['output_format']);
        }
    }

    public function testDetectUsesContentNotText(): void
    {
        $body = RequestBodyBuilder::detect('a draft text');
        $this->assertSame(['content' => 'a draft text'], $body);
        $this->assertArrayNotHasKey('text', $body);
    }
}
