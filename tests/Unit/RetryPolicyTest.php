<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use HumanTone\Internal\ErrorCondition;
use HumanTone\Internal\RetryPolicy;
use HumanTone\Tests\Support\FixedJitterSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    private function policy(int $maxRetries = 2, bool $retryOnPost = false, int $jitter = 0): RetryPolicy
    {
        return new RetryPolicy($maxRetries, $retryOnPost, new FixedJitterSource($jitter));
    }

    /**
     * Encodes the §7.2 retry matrix as a single test data set.
     * Columns: GET account, POST humanize, POST detect.
     *
     * @return iterable<string, array{ErrorCondition, string, bool, bool}>
     *   [condition, method, retryOnPost, expectedShouldRetry]
     */
    public static function matrixProvider(): iterable
    {
        // Network error
        yield 'network GET' => [ErrorCondition::Network, 'GET', false, true];
        yield 'network POST default' => [ErrorCondition::Network, 'POST', false, false];
        yield 'network POST retryOnPost' => [ErrorCondition::Network, 'POST', true, true];

        // HTTP 5xx
        yield '5xx GET' => [ErrorCondition::Http5xx, 'GET', false, true];
        yield '5xx POST default' => [ErrorCondition::Http5xx, 'POST', false, false];
        yield '5xx POST retryOnPost' => [ErrorCondition::Http5xx, 'POST', true, true];

        // HTTP 429 — always retries on every method
        yield '429 GET' => [ErrorCondition::Http429, 'GET', false, true];
        yield '429 POST default' => [ErrorCondition::Http429, 'POST', false, true];
        yield '429 POST retryOnPost' => [ErrorCondition::Http429, 'POST', true, true];

        // HTTP 4xx (not 429) — never retries
        yield '4xx other GET' => [ErrorCondition::Http4xxOther, 'GET', false, false];
        yield '4xx other POST' => [ErrorCondition::Http4xxOther, 'POST', false, false];
        yield '4xx other POST retryOnPost' => [ErrorCondition::Http4xxOther, 'POST', true, false];

        // Client-side timeout — never retries
        yield 'timeout GET' => [ErrorCondition::ClientTimeout, 'GET', false, false];
        yield 'timeout POST' => [ErrorCondition::ClientTimeout, 'POST', false, false];
        yield 'timeout POST retryOnPost' => [ErrorCondition::ClientTimeout, 'POST', true, false];

        // detect 200+success:false (transient) — always retries
        yield 'detect success:false default' => [ErrorCondition::SuccessFalseDetect, 'POST', false, true];
        yield 'detect success:false retryOnPost' => [ErrorCondition::SuccessFalseDetect, 'POST', true, true];

        // humanize 200+success:false (reserved) — POST default no, retryOnPost yes
        yield 'humanize success:false default' => [ErrorCondition::SuccessFalseHumanize, 'POST', false, false];
        yield 'humanize success:false retryOnPost' => [ErrorCondition::SuccessFalseHumanize, 'POST', true, true];

        // Parse/coercion failure on 5xx
        yield 'parse fail 5xx GET' => [ErrorCondition::ParseOrCoercionFailureOn5xx, 'GET', false, true];
        yield 'parse fail 5xx POST default' => [ErrorCondition::ParseOrCoercionFailureOn5xx, 'POST', false, false];
        yield 'parse fail 5xx POST retryOnPost' => [ErrorCondition::ParseOrCoercionFailureOn5xx, 'POST', true, true];

        // Parse/coercion failure on non-5xx — never retries
        yield 'parse fail other GET' => [ErrorCondition::ParseOrCoercionFailureOnOther, 'GET', false, false];
        yield 'parse fail other POST' => [ErrorCondition::ParseOrCoercionFailureOnOther, 'POST', false, false];
        yield 'parse fail other POST retryOnPost' => [ErrorCondition::ParseOrCoercionFailureOnOther, 'POST', true, false];
    }

    #[DataProvider('matrixProvider')]
    public function testMatrixRow(
        ErrorCondition $condition,
        string $method,
        bool $retryOnPost,
        bool $expectedShouldRetry,
    ): void {
        $policy = $this->policy(maxRetries: 2, retryOnPost: $retryOnPost);
        $decision = $policy->decide($method, $condition, retryCount: 0);
        $this->assertSame($expectedShouldRetry, $decision->shouldRetry);
    }

    public function testRetriesExhaustedReturnsNoRetry(): void
    {
        $policy = $this->policy(maxRetries: 2);
        $decision = $policy->decide('GET', ErrorCondition::Http5xx, retryCount: 2);
        $this->assertFalse($decision->shouldRetry);
    }

    public function testFirstRetryUses500MsBaseFor5xx(): void
    {
        $policy = $this->policy(jitter: 0);
        $decision = $policy->decide('GET', ErrorCondition::Http5xx, retryCount: 0);
        $this->assertTrue($decision->shouldRetry);
        $this->assertSame(500, $decision->delayMs);
    }

    public function testSecondRetryDoubles5xxBackoff(): void
    {
        $policy = $this->policy(maxRetries: 3, jitter: 0);
        $decision = $policy->decide('GET', ErrorCondition::Http5xx, retryCount: 1);
        $this->assertSame(1000, $decision->delayMs);
    }

    public function test5xxBackoffJitterUpperBound(): void
    {
        $policy = $this->policy(jitter: 200);
        $decision = $policy->decide('GET', ErrorCondition::Http5xx, retryCount: 0);
        $this->assertSame(700, $decision->delayMs);
    }

    public function test5xxBackoffJitterLowerBound(): void
    {
        $policy = $this->policy(jitter: -200);
        $decision = $policy->decide('GET', ErrorCondition::Http5xx, retryCount: 0);
        $this->assertSame(300, $decision->delayMs);
    }

    public function testNetworkBackoffSameAs5xx(): void
    {
        $policy = $this->policy(jitter: 0);
        $decision = $policy->decide('GET', ErrorCondition::Network, retryCount: 0);
        $this->assertSame(500, $decision->delayMs);
    }

    public function testDetectSuccessFalseUses5xxBackoff(): void
    {
        $policy = $this->policy(jitter: 0);
        $decision = $policy->decide('POST', ErrorCondition::SuccessFalseDetect, retryCount: 0);
        $this->assertSame(500, $decision->delayMs);
    }

    public function test429WithRetryAfterHonoredAndJittered(): void
    {
        $policy = $this->policy(jitter: 0);
        $decision = $policy->decide('POST', ErrorCondition::Http429, retryCount: 0, retryAfterSeconds: 3);
        $this->assertSame(3000, $decision->delayMs);
    }

    public function test429WithoutRetryAfterUses1sBase(): void
    {
        $policy = $this->policy(jitter: 0);
        $decision = $policy->decide('POST', ErrorCondition::Http429, retryCount: 0);
        $this->assertSame(1000, $decision->delayMs);
    }

    public function test429WithoutRetryAfterDoublesEachAttempt(): void
    {
        $policy = $this->policy(maxRetries: 3, jitter: 0);
        $first = $policy->decide('POST', ErrorCondition::Http429, retryCount: 0);
        $second = $policy->decide('POST', ErrorCondition::Http429, retryCount: 1);
        $this->assertSame(1000, $first->delayMs);
        $this->assertSame(2000, $second->delayMs);
    }

    public function test429WithRetryAfterZeroFallsBackToExponential(): void
    {
        $policy = $this->policy(jitter: 0);
        $decision = $policy->decide('POST', ErrorCondition::Http429, retryCount: 0, retryAfterSeconds: 0);
        $this->assertSame(1000, $decision->delayMs);
    }

    public function testParseFailureOn5xxUses5xxBackoff(): void
    {
        $policy = $this->policy(jitter: 0);
        $decision = $policy->decide('GET', ErrorCondition::ParseOrCoercionFailureOn5xx, retryCount: 0);
        $this->assertSame(500, $decision->delayMs);
    }

    public function testNeverRetryConditionReturnsZeroDelay(): void
    {
        $policy = $this->policy();
        $decision = $policy->decide('GET', ErrorCondition::Http4xxOther, retryCount: 0);
        $this->assertFalse($decision->shouldRetry);
        $this->assertSame(0, $decision->delayMs);
    }

    public function testDelayClampsAtZeroWhenJitterDominates(): void
    {
        // Negative jitter larger than base would otherwise produce a negative delay;
        // policy must clamp to 0.
        $policy = $this->policy(jitter: -1000);
        $decision = $policy->decide('GET', ErrorCondition::Http5xx, retryCount: 0);
        $this->assertSame(0, $decision->delayMs);
    }
}
