<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Exceptions\APIException;
use HumanTone\Exceptions\AuthenticationException;
use HumanTone\Exceptions\DailyLimitExceededException;
use HumanTone\Exceptions\HumanToneException;
use HumanTone\Exceptions\InsufficientCreditsException;
use HumanTone\Exceptions\InvalidRequestException;
use HumanTone\Exceptions\NetworkException;
use HumanTone\Exceptions\NotFoundException;
use HumanTone\Exceptions\PermissionException;
use HumanTone\Exceptions\RateLimitException;
use HumanTone\Exceptions\TimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{HumanToneException, bool}>
     */
    public static function isRetryableProvider(): iterable
    {
        yield 'AuthenticationException' => [new AuthenticationException('m'), false];
        yield 'PermissionException' => [new PermissionException('m'), false];
        yield 'RateLimitException' => [new RateLimitException('m'), true];
        yield 'InsufficientCreditsException' => [new InsufficientCreditsException('m'), false];
        yield 'DailyLimitExceededException' => [new DailyLimitExceededException('m'), false];
        yield 'InvalidRequestException' => [new InvalidRequestException('m'), false];
        yield 'NotFoundException' => [new NotFoundException('m'), false];
        yield 'APIException' => [new APIException('m'), true];
        yield 'TimeoutException' => [new TimeoutException('m'), false];
        yield 'NetworkException' => [new NetworkException('m'), true];
    }

    #[DataProvider('isRetryableProvider')]
    public function testIsRetryable(HumanToneException $e, bool $expected): void
    {
        $this->assertSame($expected, $e->isRetryable());
    }

    public function testCommonAccessorsRoundTrip(): void
    {
        $e = new InvalidRequestException(
            message: 'Bad input',
            statusCode: 400,
            requestId: 'req-123',
            errorCode: 'invalid_request',
            details: ['field' => 'content'],
        );

        $this->assertSame('Bad input', $e->getMessage());
        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame('req-123', $e->getRequestId());
        $this->assertSame('invalid_request', $e->getErrorCode());
        $this->assertSame(['field' => 'content'], $e->getDetails());
    }

    public function testCommonAccessorsDefaultsToNull(): void
    {
        $e = new InvalidRequestException('only message');
        $this->assertNull($e->getStatusCode());
        $this->assertNull($e->getRequestId());
        $this->assertNull($e->getErrorCode());
        $this->assertNull($e->getDetails());
    }

    public function testRateLimitExceptionRetryAfterDefaultIsZero(): void
    {
        $e = new RateLimitException('rl');
        $this->assertSame(0, $e->getRetryAfterSeconds());
    }

    public function testRateLimitExceptionRetryAfterRoundTrip(): void
    {
        $e = new RateLimitException(
            message: 'too many',
            statusCode: 429,
            retryAfterSeconds: 30,
        );
        $this->assertSame(30, $e->getRetryAfterSeconds());
        $this->assertSame(429, $e->getStatusCode());
    }

    public function testDailyLimitExceededTimeToNextRenewIsNullableAndDefaultsToNull(): void
    {
        $e = new DailyLimitExceededException('daily limit');
        $this->assertNull($e->getTimeToNextRenew());
    }

    public function testDailyLimitExceededTimeToNextRenewRoundTrip(): void
    {
        $e = new DailyLimitExceededException(
            message: 'Daily usage limit reached.',
            statusCode: 200,
            timeToNextRenew: 3600,
        );
        $this->assertSame(3600, $e->getTimeToNextRenew());
    }

    public function testDailyLimitExceededStatusIs200(): void
    {
        $e = new DailyLimitExceededException(
            message: 'Daily usage limit reached.',
            statusCode: 200,
        );
        $this->assertSame(200, $e->getStatusCode());
    }

    public function testInsufficientCreditsExceptionV1ShapeAccessorsAreNull(): void
    {
        $e = new InsufficientCreditsException('Not enough credits', statusCode: 400);
        $this->assertNull($e->getRequiredCredits());
        $this->assertNull($e->getAvailableCredits());
    }

    public function testInsufficientCreditsExceptionV2ShapeAccessorsRoundTrip(): void
    {
        $e = new InsufficientCreditsException(
            message: 'Not enough credits to humanize 1,200 words.',
            statusCode: 400,
            errorCode: 'insufficient_credits',
            details: ['required_credits' => 12, 'available_credits' => 4],
            requiredCredits: 12,
            availableCredits: 4,
        );
        $this->assertSame(12, $e->getRequiredCredits());
        $this->assertSame(4, $e->getAvailableCredits());
        $this->assertSame(['required_credits' => 12, 'available_credits' => 4], $e->getDetails());
    }

    public function testHumanToneExceptionExtendsRuntimeException(): void
    {
        $e = new APIException('boom');
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertInstanceOf(HumanToneException::class, $e);
    }

    public function testPreviousExceptionPropagation(): void
    {
        $prev = new \RuntimeException('underlying');
        $e = new NetworkException('wrap', previous: $prev);
        $this->assertSame($prev, $e->getPrevious());
    }
}
