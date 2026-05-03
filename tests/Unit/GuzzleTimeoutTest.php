<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use HumanTone\Exceptions\NetworkException;
use HumanTone\Exceptions\TimeoutException;
use HumanTone\Internal\HttpConfig;
use HumanTone\Internal\HttpTransport;
use HumanTone\Internal\RetryPolicy;
use HumanTone\Tests\Support\FixedJitterSource;
use HumanTone\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class GuzzleTimeoutTest extends TestCase
{
    private const API_KEY = 'ht_0000000000000000000000000000000000000000000000000000000000000000';

    private function transportFor(MockHandler $handler): HttpTransport
    {
        $stack = HandlerStack::create($handler);
        $client = new GuzzleClient(['handler' => $stack, 'http_errors' => false]);
        $factory = new HttpFactory();
        return new HttpTransport(
            http: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            retryPolicy: new RetryPolicy(0, false, new FixedJitterSource(0)),
            sleeper: new RecordingSleeper(),
            config: new HttpConfig(
                apiKey: self::API_KEY,
                baseUrl: 'https://api.humantone.io',
                userAgent: 'humantone-php/0.0.1 (php/8.3.1)',
                timeout: 120.0,
                maxRetries: 0,
                retryOnPost: false,
            ),
        );
    }

    public function testGuzzleConnectExceptionMapsToTimeoutException(): void
    {
        $req = new Request('POST', 'https://api.humantone.io/v1/detect');
        $handler = new MockHandler([new ConnectException('connect timed out', $req)]);
        $t = $this->transportFor($handler);

        try {
            $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', static fn (array $b): array => $b);
            $this->fail('expected TimeoutException');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('connect timed out', $e->getMessage());
            $this->assertSame('timeout', $e->getErrorCode());
            $this->assertFalse($e->isRetryable());
        }
    }

    public function testGuzzleRequestExceptionWithCurlError28MapsToTimeoutException(): void
    {
        $req = new Request('POST', 'https://api.humantone.io/v1/detect');
        $handlerContext = ['errno' => 28, 'error' => 'Operation timed out'];
        $exc = new RequestException(
            'Operation timed out',
            $req,
            null,
            null,
            $handlerContext,
        );
        $handler = new MockHandler([$exc]);
        $t = $this->transportFor($handler);

        try {
            $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', static fn (array $b): array => $b);
            $this->fail('expected TimeoutException');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function testGuzzleRequestExceptionWithoutCurlError28MapsToNetworkException(): void
    {
        $req = new Request('POST', 'https://api.humantone.io/v1/detect');
        $exc = new RequestException(
            'TLS handshake failed',
            $req,
            null,
            null,
            ['errno' => 35, 'error' => 'CURLE_SSL_CONNECT_ERROR'],
        );
        $handler = new MockHandler([$exc]);
        $t = $this->transportFor($handler);

        try {
            $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', static fn (array $b): array => $b);
            $this->fail('expected NetworkException');
        } catch (NetworkException $e) {
            $this->assertSame('network_error', $e->getErrorCode());
            $this->assertTrue($e->isRetryable());
        }
    }
}
