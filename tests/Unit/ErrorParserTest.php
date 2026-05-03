<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Exceptions\APIException;
use HumanTone\Exceptions\AuthenticationException;
use HumanTone\Exceptions\DailyLimitExceededException;
use HumanTone\Exceptions\HumanToneException;
use HumanTone\Exceptions\InsufficientCreditsException;
use HumanTone\Exceptions\InvalidRequestException;
use HumanTone\Exceptions\NotFoundException;
use HumanTone\Exceptions\PermissionException;
use HumanTone\Exceptions\RateLimitException;
use HumanTone\Internal\ErrorParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorParserTest extends TestCase
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

    // ---------- Step 1c: Success path ----------

    public function testHumanizeSuccessReturnsDecodedBody(): void
    {
        $body = ErrorParser::parse(200, self::fixture('humanize_200.json'));
        $this->assertSame('Humanized text here...', $body['content']);
        $this->assertSame('text', $body['output_format']);
        $this->assertSame(3, $body['credits_used']);
    }

    public function testDetectSuccessReturnsDecodedBody(): void
    {
        $body = ErrorParser::parse(200, self::fixture('detect_200.json'));
        $this->assertSame(87, $body['ai_score']);
        $this->assertTrue($body['success']);
    }

    public function testAccountSuccessReturnsDecodedBody(): void
    {
        $body = ErrorParser::parse(200, self::fixture('account_200.json'));
        $this->assertSame('pro_monthly', $body['plan']['id']);
        $this->assertSame(970, $body['credits']['total']);
    }

    public function testSuccessFieldZeroIsTreatedAsSuccessPath(): void
    {
        // Strict === false per §4.8 step 1b. Integer 0 is NOT literal false.
        $body = ErrorParser::parse(200, '{"success": 0, "ai_score": 50}');
        $this->assertSame(0, $body['success']);
    }

    public function testSuccessFieldNullIsTreatedAsSuccessPath(): void
    {
        $body = ErrorParser::parse(200, '{"success": null, "ai_score": 50}');
        $this->assertNull($body['success']);
    }

    public function testSuccessFieldEmptyStringIsTreatedAsSuccessPath(): void
    {
        $body = ErrorParser::parse(200, '{"success": "", "ai_score": 50}');
        $this->assertSame('', $body['success']);
    }

    public function testSuccessFieldAbsentIsTreatedAsSuccessPath(): void
    {
        $body = ErrorParser::parse(200, '{"ai_score": 50}');
        $this->assertSame(50, $body['ai_score']);
    }

    public function testSuccessFieldTrueReturnsSuccessPath(): void
    {
        $body = ErrorParser::parse(200, '{"success": true, "ai_score": 99}');
        $this->assertTrue($body['success']);
    }

    // ---------- Step 1b: 200+success:false ----------

    public function testDailyLimitMessageThrowsDailyLimitExceeded(): void
    {
        try {
            ErrorParser::parse(200, self::fixture('detect_daily_limit.json'));
            $this->fail('expected exception');
        } catch (DailyLimitExceededException $e) {
            $this->assertStringStartsWith('Daily usage limit reached', $e->getMessage());
            $this->assertSame(200, $e->getStatusCode());
            $this->assertSame('daily_limit_exceeded', $e->getErrorCode());
            $this->assertSame(3600, $e->getTimeToNextRenew());
        }
    }

    public function testDailyLimitWithVaryingSuffixStillMatchesPrefix(): void
    {
        $json = '{"success": false, "error": "Daily usage limit reached. Custom suffix here.", "time_to_next_renew": 100}';
        try {
            ErrorParser::parse(200, $json);
            $this->fail('expected exception');
        } catch (DailyLimitExceededException $e) {
            $this->assertSame(100, $e->getTimeToNextRenew());
            $this->assertSame('Daily usage limit reached. Custom suffix here.', $e->getMessage());
        }
    }

    public function testDailyLimitWithoutTimeToNextRenewReturnsNullAccessor(): void
    {
        $json = '{"success": false, "error": "Daily usage limit reached. No timing info."}';
        try {
            ErrorParser::parse(200, $json);
            $this->fail('expected exception');
        } catch (DailyLimitExceededException $e) {
            $this->assertNull($e->getTimeToNextRenew());
        }
    }

    public function testDetectServiceErrorThrowsApiException(): void
    {
        try {
            ErrorParser::parse(200, self::fixture('detect_service_error.json'));
            $this->fail('expected exception');
        } catch (APIException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $this->assertSame('api_error', $e->getErrorCode());
        }
    }

    public function testHumanize200SuccessFalseReservedThrowsApiException(): void
    {
        $json = '{"success": false, "error": "humanize transient unknown"}';
        try {
            ErrorParser::parse(200, $json);
            $this->fail('expected exception');
        } catch (APIException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $this->assertSame('humanize transient unknown', $e->getMessage());
        }
    }

    // ---------- Step 2: 4xx — cross-endpoint exact-match rows ----------

    /**
     * @return iterable<string, array{int, string, class-string<HumanToneException>, string}>
     */
    public static function exactMatchProvider(): iterable
    {
        yield 'Missing Authorization' => [
            401, 'Missing or invalid Authorization header',
            AuthenticationException::class, 'authentication_error',
        ];
        yield 'Invalid API key format' => [
            401, 'Invalid API key format', AuthenticationException::class, 'authentication_error',
        ];
        yield 'Invalid API key' => [
            401, 'Invalid API key', AuthenticationException::class, 'authentication_error',
        ];
        yield 'Revoked key' => [
            401, 'This API key has been revoked', AuthenticationException::class, 'authentication_error',
        ];
        yield 'User not found' => [
            401, 'User not found', AuthenticationException::class, 'authentication_error',
        ];
        yield '403 plan no API' => [
            403, 'Your current plan does not include API access. Please upgrade to continue.',
            PermissionException::class, 'permission_denied',
        ];
        yield '404 Not Found' => [
            404, 'Not Found', NotFoundException::class, 'not_found',
        ];
        yield '405 Method not allowed' => [
            405, 'Method not allowed', InvalidRequestException::class, 'invalid_request',
        ];
        yield '400 Invalid JSON body' => [
            400, 'Invalid JSON body', InvalidRequestException::class, 'invalid_request',
        ];
        yield '400 content is required' => [
            400, 'content is required', InvalidRequestException::class, 'invalid_request',
        ];
        yield '400 Text must be at least 30 words' => [
            400, 'Text must be at least 30 words', InvalidRequestException::class, 'text_too_short',
        ];
        yield '400 Not enough credits' => [
            400, 'Not enough credits', InsufficientCreditsException::class, 'insufficient_credits',
        ];
        yield '400 humanization_level enum' => [
            400, 'humanization_level must be one of: standard, advanced, extreme',
            InvalidRequestException::class, 'invalid_request',
        ];
        yield '400 output_format enum' => [
            400, 'output_format must be one of: html, text, markdown',
            InvalidRequestException::class, 'invalid_request',
        ];
        yield '400 custom_instructions length' => [
            400, 'custom_instructions must be 1000 characters or fewer',
            InvalidRequestException::class, 'invalid_request',
        ];
        yield '400 English-only levels' => [
            400, 'The advanced and extreme humanization levels are only available for English text',
            InvalidRequestException::class, 'language_not_supported',
        ];
        yield '404 Plan not found' => [
            404, 'Plan not found', NotFoundException::class, 'not_found',
        ];
    }

    #[DataProvider('exactMatchProvider')]
    public function testCatalogExactRow(int $status, string $message, string $expectedClass, string $expectedCode): void
    {
        $json = json_encode(['error' => $message], JSON_THROW_ON_ERROR);
        try {
            ErrorParser::parse($status, $json);
            $this->fail("expected exception for status $status / $message");
        } catch (HumanToneException $e) {
            $this->assertInstanceOf($expectedClass, $e);
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($expectedCode, $e->getErrorCode());
        }
    }

    // ---------- Pattern-match row ----------

    public function testTextExceedsMaximumPatternMatchN750(): void
    {
        $msg = 'Text exceeds the maximum of 750 words allowed on your plan';
        $json = json_encode(['error' => $msg], JSON_THROW_ON_ERROR);
        try {
            ErrorParser::parse(400, $json);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('text_too_long', $e->getErrorCode());
            $this->assertSame($msg, $e->getMessage());
        }
    }

    public function testTextExceedsMaximumPatternMatchN1500(): void
    {
        $msg = 'Text exceeds the maximum of 1500 words allowed on your plan';
        $json = json_encode(['error' => $msg], JSON_THROW_ON_ERROR);
        try {
            ErrorParser::parse(400, $json);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('text_too_long', $e->getErrorCode());
        }
    }

    // ---------- Prefix-match row ----------

    public function testSafetyCheckPrefixMatch(): void
    {
        $msg = 'Your request did not pass the safety check (some details).';
        $json = json_encode(['error' => $msg], JSON_THROW_ON_ERROR);
        try {
            ErrorParser::parse(400, $json);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('safety_check_failed', $e->getErrorCode());
            $this->assertSame($msg, $e->getMessage());
        }
    }

    // ---------- 429 (any-message) ----------

    public function test429WithMessageThrowsRateLimitException(): void
    {
        $json = '{"error": "Too many requests"}';
        try {
            ErrorParser::parse(429, $json);
            $this->fail('expected');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('rate_limit', $e->getErrorCode());
            $this->assertSame('Too many requests', $e->getMessage());
            $this->assertSame(0, $e->getRetryAfterSeconds());
        }
    }

    public function test429WithEmptyBodyAndRetryAfterHeader(): void
    {
        try {
            ErrorParser::parse(429, '{}', ['Retry-After' => '30']);
            $this->fail('expected');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfterSeconds());
        }
    }

    public function test429WithRetryAfterHeaderCaseInsensitive(): void
    {
        try {
            ErrorParser::parse(429, '{}', ['retry-after' => '15']);
            $this->fail('expected');
        } catch (RateLimitException $e) {
            $this->assertSame(15, $e->getRetryAfterSeconds());
        }
    }

    public function test429WithArbitraryStructuredBodyStillThrowsRateLimit(): void
    {
        try {
            ErrorParser::parse(429, '{"foo": "bar"}');
            $this->fail('expected');
        } catch (RateLimitException $e) {
            $this->assertSame('rate_limit', $e->getErrorCode());
        }
    }

    // ---------- 5xx ----------

    public function test500ThrowsApiException(): void
    {
        $json = '{"error": "Internal server error"}';
        try {
            ErrorParser::parse(500, $json);
            $this->fail('expected');
        } catch (APIException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('api_error', $e->getErrorCode());
            $this->assertSame('Internal server error', $e->getMessage());
        }
    }

    public function test503ThrowsApiException(): void
    {
        try {
            ErrorParser::parse(503, '{"error": "Service unavailable"}');
            $this->fail('expected');
        } catch (APIException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    // ---------- 4xx fallback (unknown message) ----------

    public function testUnknown401MessageFallsBackToAuthentication(): void
    {
        $json = '{"error": "some new 401 message we never saw"}';
        try {
            ErrorParser::parse(401, $json);
            $this->fail('expected');
        } catch (AuthenticationException $e) {
            $this->assertSame('authentication_error', $e->getErrorCode());
            $this->assertSame('some new 401 message we never saw', $e->getMessage());
        }
    }

    public function testUnknown400MessageFallsBackToInvalidRequest(): void
    {
        $json = '{"error": "totally novel 400 problem"}';
        try {
            ErrorParser::parse(400, $json);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('invalid_request', $e->getErrorCode());
        }
    }

    public function testExactMatchMutationFallsBackToHttpStatus(): void
    {
        // Intentionally mutate "Invalid API key" by adding punctuation —
        // the §4.7 exact-match row no longer matches, but the §4.11 HTTP-status
        // fallback for 401 still gives AuthenticationException.
        $json = '{"error": "Invalid API key!"}';
        try {
            ErrorParser::parse(401, $json);
            $this->fail('expected');
        } catch (AuthenticationException $e) {
            $this->assertSame('Invalid API key!', $e->getMessage());
            $this->assertSame('authentication_error', $e->getErrorCode());
        }
    }

    // ---------- Step 7: parse failure ----------

    public function testParseFailureOn5xxIsRetryableViaIsRetryable(): void
    {
        try {
            ErrorParser::parse(500, '<html>not json</html>');
            $this->fail('expected');
        } catch (APIException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertSame(
                'Failed to parse HumanTone API response as JSON. See exception details.',
                $e->getMessage(),
            );
            $details = $e->getDetails();
            $this->assertNotNull($details);
            $this->assertArrayHasKey('raw_body', $details);
            $this->assertArrayHasKey('parse_error', $details);
        }
    }

    public function testParseFailureOn2xxThrowsApiException(): void
    {
        try {
            ErrorParser::parse(200, 'not json at all');
            $this->fail('expected');
        } catch (APIException $e) {
            $this->assertSame(200, $e->getStatusCode());
            $this->assertNotNull($e->getDetails());
        }
    }

    public function testParseFailureRawBodyTruncatedTo4KB(): void
    {
        $bigBody = str_repeat('A', 10_000);
        try {
            ErrorParser::parse(500, $bigBody);
            $this->fail('expected');
        } catch (APIException $e) {
            $details = $e->getDetails();
            $this->assertNotNull($details);
            $this->assertIsString($details['raw_body']);
            $this->assertLessThanOrEqual(4096, strlen($details['raw_body']));
        }
    }

    public function testNonObjectJsonTriggersParseFailurePath(): void
    {
        // `[]` parses as JSON but is not an object — still treated as parse failure.
        try {
            ErrorParser::parse(200, '"a string"');
            $this->fail('expected');
        } catch (APIException $e) {
            $this->assertNotNull($e->getDetails());
        }
    }

    // ---------- §4.6 v2 shape ----------

    public function testV2ShapeKnownCodeUsesTypedException(): void
    {
        try {
            ErrorParser::parse(400, self::fixture('error_v2_insufficient_credits.json'));
            $this->fail('expected');
        } catch (InsufficientCreditsException $e) {
            $this->assertSame('insufficient_credits', $e->getErrorCode());
            $this->assertSame('Not enough credits to humanize 1,200 words.', $e->getMessage());
            $this->assertSame(12, $e->getRequiredCredits());
            $this->assertSame(4, $e->getAvailableCredits());
            $this->assertSame(['required_credits' => 12, 'available_credits' => 4], $e->getDetails());
        }
    }

    public function testV2ShapeKnownCodeRequestIdAtTopLevel(): void
    {
        try {
            ErrorParser::parse(400, self::fixture('error_v2_insufficient_credits.json'));
            $this->fail('expected');
        } catch (InsufficientCreditsException $e) {
            $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $e->getRequestId());
        }
    }

    public function testV2ShapeUnknownCodeFallsBackToHttpStatus(): void
    {
        try {
            ErrorParser::parse(400, self::fixture('error_v2_unknown_code.json'));
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('Some unknown error', $e->getMessage());
            $this->assertSame('spurious_unknown_code', $e->getErrorCode());
        }
    }

    public function testV2ShapeRateLimitCode(): void
    {
        $json = '{"error": {"code": "rate_limit", "message": "slow down"}, "request_id": "r-1"}';
        try {
            ErrorParser::parse(429, $json);
            $this->fail('expected');
        } catch (RateLimitException $e) {
            $this->assertSame('rate_limit', $e->getErrorCode());
            $this->assertSame('slow down', $e->getMessage());
        }
    }

    // ---------- §4.14 request_id resolution ----------

    public function testRequestIdFromBodyTakesPrecedenceOverHeader(): void
    {
        $json = '{"error": "x", "request_id": "from-body"}';
        try {
            ErrorParser::parse(400, $json, ['X-Request-Id' => 'from-header']);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('from-body', $e->getRequestId());
        }
    }

    public function testRequestIdFromHeaderWhenBodyOmits(): void
    {
        try {
            ErrorParser::parse(400, '{"error": "x"}', ['X-Request-Id' => 'from-header']);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('from-header', $e->getRequestId());
        }
    }

    public function testRequestIdNullWhenBothAbsent(): void
    {
        try {
            ErrorParser::parse(400, '{"error": "x"}');
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertNull($e->getRequestId());
        }
    }

    public function testRequestIdHeaderLookupCaseInsensitive(): void
    {
        try {
            ErrorParser::parse(400, '{"error": "x"}', ['x-request-id' => 'lower']);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('lower', $e->getRequestId());
        }
    }

    public function testNonStringRequestIdInBodyIgnored(): void
    {
        $json = '{"error": "x", "request_id": 42}';
        try {
            ErrorParser::parse(400, $json, ['X-Request-Id' => 'fallback']);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('fallback', $e->getRequestId());
        }
    }

    // ---------- Mixed scenarios ----------

    public function testCaseInsensitiveSubstringStillMatches(): void
    {
        // Match needles are case-insensitive per §4.11.
        $json = '{"error": "NOT ENOUGH CREDITS for this request"}';
        try {
            ErrorParser::parse(400, $json);
            $this->fail('expected');
        } catch (InsufficientCreditsException $e) {
            $this->assertSame('insufficient_credits', $e->getErrorCode());
        }
    }
}
