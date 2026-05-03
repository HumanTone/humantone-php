<?php

declare(strict_types=1);

namespace HumanTone\Internal;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use HumanTone\Exceptions\APIException;
use HumanTone\Exceptions\HumanToneException;
use HumanTone\Exceptions\NetworkException;
use HumanTone\Exceptions\RateLimitException;
use HumanTone\Exceptions\TimeoutException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface as Psr18Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Wraps a PSR-18 client. Builds requests, applies headers per §4.2,
 * runs the §7.2 retry loop, and maps low-level exceptions into the
 * SDK's typed exception hierarchy per §7.6.1.
 *
 * The decoder callable runs per attempt — coercion failures inside
 * the decoder participate in the retry decision exactly like JSON
 * parse failures (matrix row "JSON parse failure or coercion failure").
 */
final class HttpTransport
{
    public function __construct(
        private readonly Psr18Client $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RetryPolicy $retryPolicy,
        private readonly Sleeper $sleeper,
        private readonly HttpConfig $config,
    ) {
    }

    /**
     * Sends a request, parses the response, decodes via $decoder, and
     * retries per §7.2 on retryable failures.
     *
     * @param array<string, mixed>|null $jsonBody
     * @param Closure(array<string, mixed>, string): mixed $decoder
     * @param 'humanize'|'detect'|'account' $endpoint
     */
    public function send(
        string $method,
        string $path,
        ?array $jsonBody,
        string $endpoint,
        Closure $decoder,
    ): mixed {
        $retryCount = 0;

        while (true) {
            $thrown = null;
            $condition = null;
            $retryAfter = null;

            try {
                $request = $this->buildRequest($method, $path, $jsonBody);
                $response = $this->http->sendRequest($request);
                $status = $response->getStatusCode();
                $rawBody = (string) $response->getBody();
                $headers = $this->headersFromResponse($response);

                /** @var array<string, mixed> $decoded */
                $decoded = ErrorParser::parse($status, $rawBody, $headers);
                return $decoder($decoded, $rawBody);
            } catch (HumanToneException $e) {
                $thrown = $e;
                $condition = $this->classifyHumanToneException($e, $endpoint);
                if ($e instanceof RateLimitException) {
                    $retryAfter = $e->getRetryAfterSeconds();
                }
            } catch (ClientExceptionInterface $e) {
                $thrown = $this->mapClientException($e);
                $condition = $thrown instanceof TimeoutException
                    ? ErrorCondition::ClientTimeout
                    : ErrorCondition::Network;
            } catch (Throwable $e) {
                // Defensive: anything else escapes as APIException.
                throw new APIException(
                    message: 'Unexpected transport failure: ' . $e->getMessage(),
                    errorCode: 'api_error',
                    previous: $e,
                );
            }

            $decision = $this->retryPolicy->decide($method, $condition, $retryCount, $retryAfter);
            if (!$decision->shouldRetry) {
                throw $thrown;
            }
            $this->sleeper->sleepMs($decision->delayMs);
            $retryCount++;
        }
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     */
    private function buildRequest(string $method, string $path, ?array $jsonBody): RequestInterface
    {
        $url = rtrim($this->config->baseUrl, '/') . $path;
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Bearer ' . $this->config->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->config->userAgent);

        if ($jsonBody !== null) {
            try {
                $body = json_encode($jsonBody, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new APIException(
                    message: 'Failed to encode request body: ' . $e->getMessage(),
                    errorCode: 'api_error',
                    previous: $e,
                );
            }
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        return $request;
    }

    /**
     * @return array<string, list<string>>
     */
    private function headersFromResponse(ResponseInterface $response): array
    {
        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $out[$name] = array_values($values);
        }
        return $out;
    }

    /**
     * Classifies a parse/decode-time HumanToneException into a retry condition.
     *
     * The mapping must distinguish "real" HTTP-status-driven exceptions from
     * parse failures (raw_body+parse_error details), coercion failures
     * (raw_body+coercion_error details), and the 200+success:false cases
     * (per-endpoint discrimination).
     */
    private function classifyHumanToneException(HumanToneException $e, string $endpoint): ErrorCondition
    {
        if ($e instanceof RateLimitException) {
            return ErrorCondition::Http429;
        }

        if ($e instanceof APIException) {
            $details = $e->getDetails();
            $isFailure = $details !== null
                && (array_key_exists('parse_error', $details) || array_key_exists('coercion_error', $details));
            $status = $e->getStatusCode();
            $is5xx = $status !== null && $status >= 500 && $status < 600;

            if ($isFailure) {
                return $is5xx
                    ? ErrorCondition::ParseOrCoercionFailureOn5xx
                    : ErrorCondition::ParseOrCoercionFailureOnOther;
            }

            if ($status === 200) {
                return $endpoint === 'detect'
                    ? ErrorCondition::SuccessFalseDetect
                    : ErrorCondition::SuccessFalseHumanize;
            }

            if ($is5xx) {
                return ErrorCondition::Http5xx;
            }

            // Unknown status (e.g. coercion failure with statusCode=null on a
            // non-5xx attempt) — treat as non-5xx parse/coercion failure: no
            // retry by policy. Encoded as ParseOrCoercionFailureOnOther so the
            // matrix returns NO retry.
            return ErrorCondition::ParseOrCoercionFailureOnOther;
        }

        // 4xx-derived exceptions (Auth/Permission/NotFound/InvalidRequest/
        // InsufficientCredits/DailyLimit) — never retry.
        return ErrorCondition::Http4xxOther;
    }

    /**
     * Maps a PSR-18 ClientExceptionInterface to the SDK exception hierarchy
     * per §7.6.1.
     */
    private function mapClientException(ClientExceptionInterface $e): HumanToneException
    {
        // Guzzle: ConnectException is documented as connect-phase timeout in §7.6.1.
        if ($e instanceof ConnectException) {
            return new TimeoutException(
                message: $e->getMessage(),
                errorCode: 'timeout',
                previous: $e,
            );
        }

        // Guzzle: RequestException with cURL errno 28 == CURLE_OPERATION_TIMEDOUT.
        if ($e instanceof RequestException) {
            $context = $e->getHandlerContext();
            if (isset($context['errno']) && (int) $context['errno'] === 28) {
                return new TimeoutException(
                    message: $e->getMessage(),
                    errorCode: 'timeout',
                    previous: $e,
                );
            }
        }

        // Conservative: anything else from a PSR-18 client → NetworkException.
        return new NetworkException(
            message: $e->getMessage(),
            errorCode: 'network_error',
            previous: $e,
        );
    }
}
