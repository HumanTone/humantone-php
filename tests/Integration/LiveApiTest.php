<?php

declare(strict_types=1);

namespace HumanTone\Tests\Integration;

use HumanTone\Client;
use HumanTone\Enums\HumanizationLevel;
use HumanTone\Exceptions\AuthenticationException;
use HumanTone\Models\AccountInfo;
use HumanTone\Models\DetectResult;
use HumanTone\Models\HumanizeResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the live HumanTone API.
 *
 * Skipped unless HUMANTONE_TEST_API_KEY is set in the environment.
 * Per §9.4: run locally before each release; not in CI for external
 * contributors.
 */
#[Group('integration')]
final class LiveApiTest extends TestCase
{
    /** Structurally-valid key (matches /^ht_[0-9a-f]{64}$/) but never issued. */
    private const NEVER_ISSUED_KEY = 'ht_deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    private const SAMPLE_TEXT_50_WORDS = 'Artificial intelligence has rapidly changed how content teams work. Writers now use AI tools to draft, edit, and polish content across every channel they care about. Whether you are working on landing pages, marketing emails, or long form blog posts, knowing how AI fits into your workflow matters today.';

    private function client(): Client
    {
        $key = getenv('HUMANTONE_TEST_API_KEY');
        if (!is_string($key) || trim($key) === '') {
            $this->markTestSkipped('HUMANTONE_TEST_API_KEY not set — skipping live integration test.');
        }
        return new Client(apiKey: $key);
    }

    public function testHumanizeStandardLevel(): void
    {
        $client = $this->client();
        $result = $client->humanize(self::SAMPLE_TEXT_50_WORDS, HumanizationLevel::Standard);
        $this->assertInstanceOf(HumanizeResult::class, $result);
        $this->assertNotSame('', $result->text);
        $this->assertGreaterThan(0, $result->creditsUsed);
    }

    public function testHumanizeAdvancedLevel(): void
    {
        $client = $this->client();
        $result = $client->humanize(self::SAMPLE_TEXT_50_WORDS, HumanizationLevel::Advanced);
        $this->assertInstanceOf(HumanizeResult::class, $result);
        $this->assertNotSame('', $result->text);
    }

    public function testHumanizeExtremeLevel(): void
    {
        $client = $this->client();
        $result = $client->humanize(self::SAMPLE_TEXT_50_WORDS, HumanizationLevel::Extreme);
        $this->assertInstanceOf(HumanizeResult::class, $result);
        $this->assertNotSame('', $result->text);
    }

    public function testDetectHappyPath(): void
    {
        $client = $this->client();
        $result = $client->detect(self::SAMPLE_TEXT_50_WORDS);
        $this->assertInstanceOf(DetectResult::class, $result);
        $this->assertGreaterThanOrEqual(0, $result->aiScore);
        $this->assertLessThanOrEqual(100, $result->aiScore);
    }

    public function testAccountReturnsApiAccessTrue(): void
    {
        $client = $this->client();
        $info = $client->account->get();
        $this->assertInstanceOf(AccountInfo::class, $info);
        $this->assertTrue(
            $info->plan->apiAccess,
            'Test key must be on a paid plan with API access enabled.',
        );
    }

    public function testHumanizeWithCustomInstructions(): void
    {
        $client = $this->client();
        $result = $client->humanize(
            text: self::SAMPLE_TEXT_50_WORDS,
            customInstructions: 'Keep the tone formal and corporate.',
        );
        $this->assertInstanceOf(HumanizeResult::class, $result);
        $this->assertNotSame('', $result->text);
        $this->assertGreaterThan(0, $result->creditsUsed);
    }

    public function testNeverIssuedStructurallyValidKeyThrowsAuthentication(): void
    {
        // Per §9.4: must be a structurally-valid key (regex passes) so the
        // request actually reaches the server, not a malformed key (which
        // would fail in the constructor with InvalidRequestException).
        $real = getenv('HUMANTONE_TEST_API_KEY');
        if (!is_string($real) || trim($real) === '') {
            $this->markTestSkipped('HUMANTONE_TEST_API_KEY not set.');
        }

        $client = new Client(apiKey: self::NEVER_ISSUED_KEY);

        $this->expectException(AuthenticationException::class);
        $client->detect('any text');
    }
}
