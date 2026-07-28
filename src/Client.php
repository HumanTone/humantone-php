<?php

declare(strict_types=1);

namespace HumanTone;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use HumanTone\Enums\HumanizationLevel;
use HumanTone\Enums\OutputFormat;
use HumanTone\Exceptions\InvalidRequestException;
use HumanTone\Internal\HttpConfig;
use HumanTone\Internal\HttpTransport;
use HumanTone\Internal\RandomJitterSource;
use HumanTone\Internal\RealSleeper;
use HumanTone\Internal\RequestBodyBuilder;
use HumanTone\Internal\ResponseDecoder;
use HumanTone\Internal\RetryPolicy;
use HumanTone\Internal\UserAgent;
use HumanTone\Internal\VersionResolver;
use HumanTone\Models\DetectResult;
use HumanTone\Models\HumanizeResult;
use Psr\Http\Client\ClientInterface as Psr18Client;

final class Client
{
    /** Hardcoded fallback. Bumped manually before each `git tag`. */
    public const SDK_VERSION = '0.0.1';

    private const API_KEY_REGEX = '/^ht_[0-9a-f]{64}$/';
    private const DEFAULT_BASE_URL = 'https://api.humantone.io';

    public readonly AccountResource $account;
    private readonly HttpTransport $transport;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 120.0,
        int $maxRetries = 2,
        bool $retryOnPost = false,
        ?Psr18Client $httpClient = null,
        ?string $userAgent = null,
    ) {
        $resolvedKey = self::resolveApiKey($apiKey);
        $resolvedBaseUrl = self::resolveBaseUrl($baseUrl);

        $factory = new HttpFactory();
        $client = $httpClient ?? new GuzzleClient([
            'connect_timeout' => $timeout,
            'timeout' => $timeout,
            'http_errors' => false,
        ]);

        $config = new HttpConfig(
            apiKey: $resolvedKey,
            baseUrl: $resolvedBaseUrl,
            userAgent: UserAgent::build(
                VersionResolver::resolve(),
                UserAgent::sanitizedPhpVersion(),
                $userAgent,
            ),
            timeout: $timeout,
            maxRetries: $maxRetries,
            retryOnPost: $retryOnPost,
        );

        $transport = new HttpTransport(
            http: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            retryPolicy: new RetryPolicy($maxRetries, $retryOnPost, new RandomJitterSource()),
            sleeper: new RealSleeper(),
            config: $config,
        );

        $this->account = new AccountResource($transport);
        $this->transport = $transport;
    }

    public function humanize(
        string $text,
        HumanizationLevel $level = HumanizationLevel::Standard,
        OutputFormat $outputFormat = OutputFormat::Text,
        ?string $customInstructions = null,
    ): HumanizeResult {
        /** @var HumanizeResult $result */
        $result = $this->transport->send(
            method: 'POST',
            path: '/v1/humanize',
            jsonBody: RequestBodyBuilder::humanize($text, $level, $outputFormat, $customInstructions),
            endpoint: 'humanize',
            decoder: static fn (array $body, string $rawBody): HumanizeResult
                => ResponseDecoder::humanize($body, $rawBody),
        );
        return $result;
    }

    public function detect(string $text): DetectResult
    {
        /** @var DetectResult $result */
        $result = $this->transport->send(
            method: 'POST',
            path: '/v1/detect',
            jsonBody: RequestBodyBuilder::detect($text),
            endpoint: 'detect',
            decoder: static fn (array $body, string $rawBody): DetectResult
                => ResponseDecoder::detect($body, $rawBody),
        );
        return $result;
    }

    private static function resolveApiKey(?string $argument): string
    {
        $candidate = self::trimToNull($argument);
        if ($candidate === null) {
            $envValue = getenv('HUMANTONE_API_KEY');
            $candidate = is_string($envValue) ? self::trimToNull($envValue) : null;
        }

        if ($candidate === null) {
            throw new InvalidRequestException(
                message: 'Missing API key. Pass apiKey to HumanTone\Client or set HUMANTONE_API_KEY environment variable. Get a key at https://humantone.io/dashboard/settings/api',
                errorCode: 'missing_api_key',
            );
        }

        if (preg_match(self::API_KEY_REGEX, $candidate) !== 1) {
            throw new InvalidRequestException(
                message: 'Invalid API key format. Expected ht_ prefix followed by 64 hex characters.',
                errorCode: 'invalid_api_key_format',
            );
        }

        return $candidate;
    }

    private static function resolveBaseUrl(?string $argument): string
    {
        $candidate = self::trimToNull($argument);
        if ($candidate === null) {
            $envValue = getenv('HUMANTONE_BASE_URL');
            $candidate = is_string($envValue) ? self::trimToNull($envValue) : null;
        }
        return $candidate ?? self::DEFAULT_BASE_URL;
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
