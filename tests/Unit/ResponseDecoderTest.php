<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use HumanTone\Enums\OutputFormat;
use HumanTone\Exceptions\APIException;
use HumanTone\Internal\ResponseDecoder;
use PHPUnit\Framework\TestCase;

final class ResponseDecoderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures';

    private static function fixture(string $name): string
    {
        $path = self::FIXTURES . '/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read fixture: $path");
        }
        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedFixture(string $name): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::fixture($name), true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    // ---------- Happy paths ----------

    public function testHumanizeRenamesContentToText(): void
    {
        $r = ResponseDecoder::humanize(self::decodedFixture('humanize_200.json'), self::fixture('humanize_200.json'));
        $this->assertSame('Humanized text here...', $r->text);
        $this->assertSame(OutputFormat::Text, $r->outputFormat);
        $this->assertSame(3, $r->creditsUsed);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $r->requestId);
    }

    public function testDetectHappyPath(): void
    {
        $r = ResponseDecoder::detect(self::decodedFixture('detect_200.json'), self::fixture('detect_200.json'));
        $this->assertSame(87, $r->aiScore);
        $this->assertNull($r->requestId);
    }

    public function testAccountHappyPath(): void
    {
        $info = ResponseDecoder::account(self::decodedFixture('account_200.json'), self::fixture('account_200.json'));
        $this->assertSame('pro_monthly', $info->plan->id);
        $this->assertSame('Pro Monthly', $info->plan->name);
        $this->assertSame(1500, $info->plan->maxWords);
        $this->assertSame(1000, $info->plan->monthlyCredits);
        $this->assertTrue($info->plan->apiAccess);
        $this->assertSame(0, $info->credits->trial);
        $this->assertSame(820, $info->credits->subscription);
        $this->assertSame(150, $info->credits->extra);
        $this->assertSame(970, $info->credits->total);
        $this->assertTrue($info->subscription->active);
        $this->assertInstanceOf(DateTimeImmutable::class, $info->subscription->expiresAt);
    }

    public function testAccountExpiresAtParsedAsUtc(): void
    {
        $info = ResponseDecoder::account(self::decodedFixture('account_200.json'), '');
        $expires = $info->subscription->expiresAt;
        $this->assertNotNull($expires);
        $this->assertSame('2026-05-08', $expires->format('Y-m-d'));
        $expected = new DateTimeImmutable('2026-05-08T00:00:00.000Z');
        $this->assertSame($expected->getTimestamp(), $expires->getTimestamp());
        $this->assertSame('UTC', $expires->setTimezone(new DateTimeZone('UTC'))->format('e'));
    }

    public function testSubscriptionExpiresAtMissingIsNull(): void
    {
        $body = [
            'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100, 'api_access' => true],
            'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0, 'total' => 100],
            'subscription' => ['active' => true],
        ];
        $info = ResponseDecoder::account($body, json_encode($body, JSON_THROW_ON_ERROR));
        $this->assertNull($info->subscription->expiresAt);
    }

    public function testSubscriptionExpiresAtJsonNullIsNull(): void
    {
        $body = [
            'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100, 'api_access' => true],
            'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0, 'total' => 100],
            'subscription' => ['active' => false, 'expires_at' => null],
        ];
        $info = ResponseDecoder::account($body, json_encode($body, JSON_THROW_ON_ERROR));
        $this->assertNull($info->subscription->expiresAt);
    }

    // ---------- Coercion failures ----------

    private function expectCoercion(string $reasonSubstring, callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected APIException');
        } catch (APIException $e) {
            $this->assertSame(
                'Malformed response from HumanTone API. See exception details.',
                $e->getMessage(),
            );
            $details = $e->getDetails();
            $this->assertNotNull($details);
            $this->assertArrayHasKey('raw_body', $details);
            $this->assertArrayHasKey('coercion_error', $details);
            $this->assertStringContainsString($reasonSubstring, (string) $details['coercion_error']);
        }
    }

    public function testCoercionAiScoreMissing(): void
    {
        $this->expectCoercion('ai_score', function () {
            ResponseDecoder::detect(['success' => true], '{}');
        });
    }

    public function testCoercionAiScoreNonInt(): void
    {
        $this->expectCoercion('ai_score', function () {
            ResponseDecoder::detect(['success' => true, 'ai_score' => 'high'], '{}');
        });
    }

    public function testCoercionAiScoreOutOfRange(): void
    {
        $this->expectCoercion('out of', function () {
            ResponseDecoder::detect(['success' => true, 'ai_score' => 101], '{}');
        });
    }

    public function testCoercionAiScoreNegative(): void
    {
        $this->expectCoercion('out of', function () {
            ResponseDecoder::detect(['success' => true, 'ai_score' => -1], '{}');
        });
    }

    public function testCoercionHumanizeOutputFormatMissing(): void
    {
        $this->expectCoercion('output_format', function () {
            ResponseDecoder::humanize(
                ['content' => 'x', 'credits_used' => 1, 'request_id' => 'r'],
                '{}',
            );
        });
    }

    public function testCoercionHumanizeOutputFormatUnknownEnumNotSilentFallback(): void
    {
        $this->expectCoercion("unknown enum case 'csv'", function () {
            ResponseDecoder::humanize(
                ['content' => 'x', 'output_format' => 'csv', 'credits_used' => 1, 'request_id' => 'r'],
                '{}',
            );
        });
    }

    public function testCoercionHumanizeContentMissing(): void
    {
        $this->expectCoercion('content', function () {
            ResponseDecoder::humanize(
                ['output_format' => 'text', 'credits_used' => 1, 'request_id' => 'r'],
                '{}',
            );
        });
    }

    public function testCoercionHumanizeCreditsUsedMissing(): void
    {
        $this->expectCoercion('credits_used', function () {
            ResponseDecoder::humanize(
                ['content' => 'x', 'output_format' => 'text', 'request_id' => 'r'],
                '{}',
            );
        });
    }

    public function testCoercionAccountPlanMissing(): void
    {
        $this->expectCoercion('plan', function () {
            ResponseDecoder::account([
                'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0, 'total' => 100],
                'subscription' => ['active' => true, 'expires_at' => null],
            ], '{}');
        });
    }

    public function testCoercionAccountCreditsMissing(): void
    {
        $this->expectCoercion('credits', function () {
            ResponseDecoder::account([
                'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100, 'api_access' => true],
                'subscription' => ['active' => true, 'expires_at' => null],
            ], '{}');
        });
    }

    public function testCoercionAccountSubscriptionMissing(): void
    {
        $this->expectCoercion('subscription', function () {
            ResponseDecoder::account([
                'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100, 'api_access' => true],
                'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0, 'total' => 100],
            ], '{}');
        });
    }

    public function testCoercionAccountPlanFieldsMissing(): void
    {
        $this->expectCoercion('plan.api_access', function () {
            ResponseDecoder::account([
                'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100],
                'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0, 'total' => 100],
                'subscription' => ['active' => true, 'expires_at' => null],
            ], '{}');
        });
    }

    public function testCoercionCreditsTotalMissing(): void
    {
        $this->expectCoercion('credits.total', function () {
            ResponseDecoder::account([
                'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100, 'api_access' => true],
                'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0],
                'subscription' => ['active' => true, 'expires_at' => null],
            ], '{}');
        });
    }

    public function testCoercionExpiresAtNonParseable(): void
    {
        $body = [
            'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100, 'api_access' => true],
            'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0, 'total' => 100],
            'subscription' => ['active' => true, 'expires_at' => 'not a date at all'],
        ];
        $this->expectCoercion('expires_at', function () use ($body) {
            ResponseDecoder::account($body, '{}');
        });
    }

    public function testCoercionExpiresAtNonString(): void
    {
        $body = [
            'plan' => ['id' => 'p', 'name' => 'P', 'max_words' => 750, 'monthly_credits' => 100, 'api_access' => true],
            'credits' => ['trial' => 0, 'subscription' => 100, 'extra' => 0, 'total' => 100],
            'subscription' => ['active' => true, 'expires_at' => 12345],
        ];
        $this->expectCoercion('expires_at', function () use ($body) {
            ResponseDecoder::account($body, '{}');
        });
    }

    public function testCoercionRequestIdNonString(): void
    {
        $this->expectCoercion('request_id', function () {
            ResponseDecoder::detect(['ai_score' => 50, 'request_id' => 42], '{}');
        });
    }

    public function testRequestIdMissingIsOk(): void
    {
        $r = ResponseDecoder::detect(['ai_score' => 50], '{}');
        $this->assertNull($r->requestId);
    }

    public function testRequestIdJsonNullIsOk(): void
    {
        $r = ResponseDecoder::detect(['ai_score' => 50, 'request_id' => null], '{}');
        $this->assertNull($r->requestId);
    }

    public function testRawBodyTruncatedTo4kbInDetails(): void
    {
        $bigRaw = str_repeat('A', 10_000);
        try {
            ResponseDecoder::detect(['ai_score' => 'bad'], $bigRaw);
            $this->fail('expected');
        } catch (APIException $e) {
            $details = $e->getDetails();
            $this->assertNotNull($details);
            $this->assertIsString($details['raw_body']);
            $this->assertLessThanOrEqual(4096, strlen($details['raw_body']));
        }
    }
}
