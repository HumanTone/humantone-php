<?php

declare(strict_types=1);

namespace HumanTone\Internal;

use DateTimeImmutable;
use Exception;
use HumanTone\Enums\OutputFormat;
use HumanTone\Exceptions\APIException;
use HumanTone\Models\AccountInfo;
use HumanTone\Models\Credits;
use HumanTone\Models\DetectResult;
use HumanTone\Models\HumanizeResult;
use HumanTone\Models\Plan;
use HumanTone\Models\Subscription;

/**
 * Builds typed DTOs from decoded API response bodies.
 *
 * Implements §4.8 step 8 (type coercion). Any shape mismatch — missing
 * required fields, wrong types, out-of-range values, non-parseable
 * dates, unknown enum cases — results in an {@see APIException} with
 * coercion_error details.
 */
final class ResponseDecoder
{
    private const RAW_BODY_TRUNCATION = 4096;

    /**
     * @param array<string, mixed> $body
     */
    public static function humanize(array $body, string $rawBody): HumanizeResult
    {
        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            self::coercionFail($rawBody, 'content: missing or not a string');
        }

        $formatRaw = $body['output_format'] ?? null;
        if (!is_string($formatRaw)) {
            self::coercionFail($rawBody, 'output_format: missing or not a string');
        }
        $format = OutputFormat::tryFrom($formatRaw);
        if ($format === null) {
            self::coercionFail($rawBody, "output_format: unknown enum case '$formatRaw'");
        }

        $creditsUsed = $body['credits_used'] ?? null;
        if (!is_int($creditsUsed)) {
            self::coercionFail($rawBody, 'credits_used: missing or not an integer');
        }

        $requestId = self::optionalStringOrCoerce($body, 'request_id', $rawBody);

        return new HumanizeResult(
            text: $content,
            outputFormat: $format,
            creditsUsed: $creditsUsed,
            requestId: $requestId,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function detect(array $body, string $rawBody): DetectResult
    {
        $score = $body['ai_score'] ?? null;
        if (!is_int($score)) {
            self::coercionFail($rawBody, 'ai_score: missing or not an integer');
        }
        if ($score < 0 || $score > 100) {
            self::coercionFail($rawBody, "ai_score: out of [0, 100] range ($score)");
        }

        $requestId = self::optionalStringOrCoerce($body, 'request_id', $rawBody);

        return new DetectResult(aiScore: $score, requestId: $requestId);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function account(array $body, string $rawBody): AccountInfo
    {
        $planArr = $body['plan'] ?? null;
        if (!is_array($planArr)) {
            self::coercionFail($rawBody, 'plan: missing or not an object');
        }
        $creditsArr = $body['credits'] ?? null;
        if (!is_array($creditsArr)) {
            self::coercionFail($rawBody, 'credits: missing or not an object');
        }
        $subArr = $body['subscription'] ?? null;
        if (!is_array($subArr)) {
            self::coercionFail($rawBody, 'subscription: missing or not an object');
        }

        return new AccountInfo(
            plan: self::buildPlan($planArr, $rawBody),
            credits: self::buildCredits($creditsArr, $rawBody),
            subscription: self::buildSubscription($subArr, $rawBody),
        );
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function buildPlan(array $arr, string $rawBody): Plan
    {
        $id = $arr['id'] ?? null;
        if (!is_string($id)) {
            self::coercionFail($rawBody, 'plan.id: missing or not a string');
        }
        $name = $arr['name'] ?? null;
        if (!is_string($name)) {
            self::coercionFail($rawBody, 'plan.name: missing or not a string');
        }
        $maxWords = $arr['max_words'] ?? null;
        if (!is_int($maxWords)) {
            self::coercionFail($rawBody, 'plan.max_words: missing or not an integer');
        }
        $monthlyCredits = $arr['monthly_credits'] ?? null;
        if (!is_int($monthlyCredits)) {
            self::coercionFail($rawBody, 'plan.monthly_credits: missing or not an integer');
        }
        $apiAccess = $arr['api_access'] ?? null;
        if (!is_bool($apiAccess)) {
            self::coercionFail($rawBody, 'plan.api_access: missing or not a boolean');
        }

        return new Plan($id, $name, $maxWords, $monthlyCredits, $apiAccess);
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function buildCredits(array $arr, string $rawBody): Credits
    {
        foreach (['trial', 'subscription', 'extra', 'total'] as $field) {
            if (!is_int($arr[$field] ?? null)) {
                self::coercionFail($rawBody, "credits.$field: missing or not an integer");
            }
        }
        /** @var int $trial */
        $trial = $arr['trial'];
        /** @var int $subscription */
        $subscription = $arr['subscription'];
        /** @var int $extra */
        $extra = $arr['extra'];
        /** @var int $total */
        $total = $arr['total'];
        return new Credits($trial, $subscription, $extra, $total);
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function buildSubscription(array $arr, string $rawBody): Subscription
    {
        $active = $arr['active'] ?? null;
        if (!is_bool($active)) {
            self::coercionFail($rawBody, 'subscription.active: missing or not a boolean');
        }

        $expiresAt = null;
        if (array_key_exists('expires_at', $arr) && $arr['expires_at'] !== null) {
            $value = $arr['expires_at'];
            if (!is_string($value)) {
                self::coercionFail($rawBody, 'subscription.expires_at: present but not a string');
            }
            try {
                $expiresAt = new DateTimeImmutable($value);
            } catch (Exception $e) {
                self::coercionFail(
                    $rawBody,
                    'subscription.expires_at: non-null but not a parseable ISO 8601 string ('
                    . $e->getMessage() . ')',
                );
            }
        }

        return new Subscription(active: $active, expiresAt: $expiresAt);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalStringOrCoerce(array $body, string $field, string $rawBody): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        $value = $body[$field];
        if (!is_string($value)) {
            self::coercionFail($rawBody, "$field: present but not a string");
        }
        return $value;
    }

    /**
     * @return never
     */
    private static function coercionFail(string $rawBody, string $reason): void
    {
        $truncated = substr($rawBody, 0, self::RAW_BODY_TRUNCATION);
        throw new APIException(
            message: 'Malformed response from HumanTone API. See exception details.',
            statusCode: null,
            requestId: null,
            errorCode: 'api_error',
            details: [
                'raw_body' => $truncated,
                'coercion_error' => $reason,
            ],
        );
    }
}
