<?php

declare(strict_types=1);

namespace HumanTone\Internal;

/**
 * Pure decision policy for the §7.2 retry matrix.
 *
 * No I/O, no clock, no sleeping. Given (method, condition, retry count
 * already attempted, config, optional Retry-After), returns whether the
 * caller should retry and how long to wait.
 */
final class RetryPolicy
{
    public function __construct(
        private readonly int $maxRetries,
        private readonly bool $retryOnPost,
        private readonly JitterSource $jitter,
    ) {
    }

    public function decide(
        string $method,
        ErrorCondition $condition,
        int $retryCount,
        ?int $retryAfterSeconds = null,
    ): RetryDecision {
        if ($retryCount >= $this->maxRetries) {
            return new RetryDecision(false, 0);
        }

        $shouldRetry = $this->shouldRetryFor($method, $condition);
        if (!$shouldRetry) {
            return new RetryDecision(false, 0);
        }

        return new RetryDecision(true, $this->computeDelayMs($condition, $retryCount, $retryAfterSeconds));
    }

    private function shouldRetryFor(string $method, ErrorCondition $condition): bool
    {
        $isPost = $method === 'POST';

        return match ($condition) {
            ErrorCondition::Network, ErrorCondition::Http5xx
                => !$isPost || $this->retryOnPost,

            // 429 always retries on every method (rate-limit responses arrive
            // before any work is done server-side, so retry is safe).
            ErrorCondition::Http429
                => true,

            ErrorCondition::Http4xxOther, ErrorCondition::ClientTimeout
                => false,

            // detect 200+success:false transient — always retries (no work
            // done server-side, similar safety to 429).
            ErrorCondition::SuccessFalseDetect
                => true,

            // humanize 200+success:false (reserved) — POST default no, opt-in
            // via retryOnPost.
            ErrorCondition::SuccessFalseHumanize
                => $this->retryOnPost,

            // Parse/coercion failure on 5xx body — same as Http5xx column.
            ErrorCondition::ParseOrCoercionFailureOn5xx
                => !$isPost || $this->retryOnPost,

            // Parse/coercion failure on any non-5xx body — never retry.
            ErrorCondition::ParseOrCoercionFailureOnOther
                => false,
        };
    }

    private function computeDelayMs(
        ErrorCondition $condition,
        int $retryCount,
        ?int $retryAfterSeconds,
    ): int {
        $jitter = $this->jitter->jitterMs();

        $multiplier = 1 << $retryCount;

        if ($condition === ErrorCondition::Http429) {
            if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
                return (int) max(0, $retryAfterSeconds * 1000 + $jitter);
            }
            // 429 without Retry-After: 1s × 2^attempt + jitter.
            return (int) max(0, 1000 * $multiplier + $jitter);
        }

        // 5xx, network, success:false (detect), parse/coercion-on-5xx, etc.:
        // 500ms × 2^attempt + jitter.
        return (int) max(0, 500 * $multiplier + $jitter);
    }
}
