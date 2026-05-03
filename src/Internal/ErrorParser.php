<?php

declare(strict_types=1);

namespace HumanTone\Internal;

use HumanTone\Exceptions\APIException;
use HumanTone\Exceptions\AuthenticationException;
use HumanTone\Exceptions\DailyLimitExceededException;
use HumanTone\Exceptions\HumanToneException;
use HumanTone\Exceptions\InsufficientCreditsException;
use HumanTone\Exceptions\InvalidRequestException;
use HumanTone\Exceptions\NotFoundException;
use HumanTone\Exceptions\PermissionException;
use HumanTone\Exceptions\RateLimitException;
use JsonException;

/**
 * Implements the §4.8 error-parsing algorithm.
 *
 * Pure: no I/O, no clock. Given the response triple (status, raw body,
 * headers), either returns the decoded body on the success path or throws
 * a typed {@see HumanToneException} on the error path.
 *
 * Type coercion (step 8 — DTO field validation) is handled by
 * {@see ResponseDecoder}, not here. ResponseDecoder also throws
 * {@see APIException} with `coercion_error` details, which the transport
 * treats identically to a parse failure for retry purposes.
 */
final class ErrorParser
{
    private const RAW_BODY_TRUNCATION = 4096;

    /**
     * Specific body-error substring matchers per §4.11 (case-insensitive).
     * Order matters: first match wins. Each entry maps to (errorCode, exception class).
     *
     * @var list<array{string, string, class-string<HumanToneException>}>
     */
    private const BODY_MATCHERS = [
        ['Daily usage limit reached', 'daily_limit_exceeded', DailyLimitExceededException::class],
        ['Not enough credits', 'insufficient_credits', InsufficientCreditsException::class],
        ['at least 30 words', 'text_too_short', InvalidRequestException::class],
        ['exceeds the maximum', 'text_too_long', InvalidRequestException::class],
        ['safety check', 'safety_check_failed', InvalidRequestException::class],
        ['only available for English', 'language_not_supported', InvalidRequestException::class],
    ];

    /**
     * v2 shape: known error.code → (errorCode-on-exception is identical, exception class).
     *
     * @var array<string, class-string<HumanToneException>>
     */
    private const V2_CODE_TO_CLASS = [
        'daily_limit_exceeded' => DailyLimitExceededException::class,
        'insufficient_credits' => InsufficientCreditsException::class,
        'text_too_short' => InvalidRequestException::class,
        'text_too_long' => InvalidRequestException::class,
        'safety_check_failed' => InvalidRequestException::class,
        'language_not_supported' => InvalidRequestException::class,
        'missing_api_key' => InvalidRequestException::class,
        'invalid_api_key_format' => InvalidRequestException::class,
        'invalid_request' => InvalidRequestException::class,
        'authentication_error' => AuthenticationException::class,
        'permission_denied' => PermissionException::class,
        'not_found' => NotFoundException::class,
        'rate_limit' => RateLimitException::class,
        'api_error' => APIException::class,
    ];

    /**
     * @param array<string, string|list<string>> $headers
     * @return array<string, mixed> decoded body on the success path
     * @throws HumanToneException on the error path
     */
    public static function parse(int $statusCode, string $rawBody, array $headers = []): array
    {
        // Step 0: parse JSON. On failure → step 7.
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw self::parseFailure($statusCode, $rawBody, $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw self::parseFailure($statusCode, $rawBody, 'Top-level JSON value is not an object');
        }

        /** @var array<string, mixed> $body */
        $body = $decoded;
        $requestId = self::resolveRequestId($body, $headers);

        // Step 1: 2xx path.
        if ($statusCode >= 200 && $statusCode < 300) {
            // Step 1b: strict === false (literal boolean false only).
            if (array_key_exists('success', $body) && $body['success'] === false) {
                throw self::handleSuccessFalse($statusCode, $body, $requestId);
            }
            // Step 1c: success path. Forward-compat — do not validate success === true.
            return $body;
        }

        // Step 3: 429.
        if ($statusCode === 429) {
            $retryAfter = self::headerString($headers, 'Retry-After');
            $message = self::extractMessage($body) ?? 'Rate limit exceeded';
            throw new RateLimitException(
                message: $message,
                statusCode: 429,
                requestId: $requestId,
                errorCode: 'rate_limit',
                details: self::extractV2Details($body),
                retryAfterSeconds: RetryAfterParser::parse($retryAfter),
            );
        }

        // Step 4: 5xx.
        if ($statusCode >= 500 && $statusCode < 600) {
            $message = self::extractMessage($body) ?? 'API error';
            throw new APIException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: 'api_error',
                details: self::extractV2Details($body),
            );
        }

        // Step 2: 4xx.
        if ($statusCode >= 400 && $statusCode < 500) {
            throw self::handle4xx($statusCode, $body, $requestId);
        }

        // Other status codes (1xx, 3xx) — treat as malformed/unexpected.
        throw new APIException(
            message: 'Unexpected HTTP status ' . $statusCode,
            statusCode: $statusCode,
            requestId: $requestId,
            errorCode: 'api_error',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function handleSuccessFalse(int $statusCode, array $body, ?string $requestId): HumanToneException
    {
        $error = $body['error'] ?? null;
        if (is_string($error) && self::caseInsensitiveStartsWith($error, 'Daily usage limit reached')) {
            $ttnr = $body['time_to_next_renew'] ?? null;
            return new DailyLimitExceededException(
                message: $error,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: 'daily_limit_exceeded',
                timeToNextRenew: is_int($ttnr) ? $ttnr : null,
            );
        }

        // detect transient backend error (no message) OR humanize reserved case.
        $message = is_string($error) && $error !== '' ? $error : 'API returned success:false';
        return new APIException(
            message: $message,
            statusCode: $statusCode,
            requestId: $requestId,
            errorCode: 'api_error',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function handle4xx(int $statusCode, array $body, ?string $requestId): HumanToneException
    {
        $error = $body['error'] ?? null;

        // v2 shape: body.error is an object.
        if (is_array($error)) {
            return self::buildFromV2Shape($statusCode, $error, $requestId);
        }

        // v1 shape: body.error is a string.
        $message = is_string($error) ? $error : self::defaultMessageForStatus($statusCode);

        // Try specific substring matchers first.
        if (is_string($error)) {
            foreach (self::BODY_MATCHERS as [$needle, $errorCode, $class]) {
                if (self::caseInsensitiveContains($error, $needle)) {
                    return self::instantiate($class, $message, $statusCode, $requestId, $errorCode, null);
                }
            }
        }

        // HTTP-status fallback per §4.11.
        return self::buildFromHttpStatus($statusCode, $message, $requestId);
    }

    /**
     * @param array<string, mixed> $errorObj
     */
    private static function buildFromV2Shape(int $statusCode, array $errorObj, ?string $requestId): HumanToneException
    {
        $code = $errorObj['code'] ?? null;
        $message = $errorObj['message'] ?? null;
        $details = $errorObj['details'] ?? null;

        $messageStr = is_string($message) && $message !== ''
            ? $message
            : self::defaultMessageForStatus($statusCode);

        /** @var array<string, mixed>|null $detailsArr */
        $detailsArr = is_array($details) ? $details : null;

        if (is_string($code) && isset(self::V2_CODE_TO_CLASS[$code])) {
            $class = self::V2_CODE_TO_CLASS[$code];
            return self::instantiate($class, $messageStr, $statusCode, $requestId, $code, $detailsArr);
        }

        // Unknown or missing code → HTTP-status fallback. Preserve any provided code as the errorCode.
        $effectiveCode = is_string($code) && $code !== '' ? $code : null;
        $exception = self::buildFromHttpStatus($statusCode, $messageStr, $requestId, $effectiveCode);
        // The standard buildFromHttpStatus sets details to null; re-instantiate with details if v2 provided them.
        if ($detailsArr !== null) {
            return self::instantiate(
                $exception::class,
                $messageStr,
                $statusCode,
                $requestId,
                $exception->getErrorCode(),
                $detailsArr,
            );
        }
        return $exception;
    }

    private static function buildFromHttpStatus(
        int $statusCode,
        string $message,
        ?string $requestId,
        ?string $overrideCode = null,
    ): HumanToneException {
        return match (true) {
            $statusCode === 401 => new AuthenticationException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $overrideCode ?? 'authentication_error',
            ),
            $statusCode === 403 => new PermissionException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $overrideCode ?? 'permission_denied',
            ),
            $statusCode === 404 => new NotFoundException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $overrideCode ?? 'not_found',
            ),
            $statusCode === 429 => new RateLimitException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $overrideCode ?? 'rate_limit',
            ),
            default => new InvalidRequestException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $overrideCode ?? 'invalid_request',
            ),
        };
    }

    /**
     * @param class-string<HumanToneException> $class
     * @param array<string, mixed>|null $details
     */
    private static function instantiate(
        string $class,
        string $message,
        int $statusCode,
        ?string $requestId,
        ?string $errorCode,
        ?array $details,
    ): HumanToneException {
        // For classes with extra constructor params, populate them from details where applicable.
        if ($class === InsufficientCreditsException::class) {
            $required = $details['required_credits'] ?? null;
            $available = $details['available_credits'] ?? null;
            return new InsufficientCreditsException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $errorCode,
                details: $details,
                requiredCredits: is_int($required) ? $required : null,
                availableCredits: is_int($available) ? $available : null,
            );
        }
        if ($class === RateLimitException::class) {
            return new RateLimitException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $errorCode,
                details: $details,
            );
        }
        if ($class === DailyLimitExceededException::class) {
            return new DailyLimitExceededException(
                message: $message,
                statusCode: $statusCode,
                requestId: $requestId,
                errorCode: $errorCode,
                details: $details,
            );
        }
        return new $class(
            message: $message,
            statusCode: $statusCode,
            requestId: $requestId,
            errorCode: $errorCode,
            details: $details,
        );
    }

    private static function parseFailure(int $statusCode, string $rawBody, string $reason): APIException
    {
        $truncated = substr($rawBody, 0, self::RAW_BODY_TRUNCATION);
        return new APIException(
            message: 'Failed to parse HumanTone API response as JSON. See exception details.',
            statusCode: $statusCode,
            requestId: null,
            errorCode: 'api_error',
            details: [
                'raw_body' => $truncated,
                'parse_error' => $reason,
            ],
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string|list<string>> $headers
     */
    private static function resolveRequestId(array $body, array $headers): ?string
    {
        $bodyRid = $body['request_id'] ?? null;
        if (is_string($bodyRid) && $bodyRid !== '') {
            return $bodyRid;
        }
        return self::headerString($headers, 'X-Request-Id');
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function headerString(array $headers, string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $needle) {
                if (is_array($value)) {
                    return isset($value[0]) ? (string) $value[0] : null;
                }
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function extractMessage(array $body): ?string
    {
        $err = $body['error'] ?? null;
        if (is_string($err)) {
            return $err;
        }
        if (is_array($err)) {
            $msg = $err['message'] ?? null;
            return is_string($msg) ? $msg : null;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private static function extractV2Details(array $body): ?array
    {
        $err = $body['error'] ?? null;
        if (!is_array($err)) {
            return null;
        }
        $details = $err['details'] ?? null;
        return is_array($details) ? $details : null;
    }

    private static function defaultMessageForStatus(int $statusCode): string
    {
        return match (true) {
            $statusCode === 401 => 'Unauthorized',
            $statusCode === 403 => 'Forbidden',
            $statusCode === 404 => 'Not Found',
            $statusCode === 429 => 'Too Many Requests',
            $statusCode >= 400 && $statusCode < 500 => 'Bad Request',
            $statusCode >= 500 => 'Server Error',
            default => 'HTTP ' . $statusCode,
        };
    }

    private static function caseInsensitiveContains(string $haystack, string $needle): bool
    {
        return stripos($haystack, $needle) !== false;
    }

    private static function caseInsensitiveStartsWith(string $haystack, string $needle): bool
    {
        return stripos($haystack, $needle) === 0;
    }
}
