<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HumanTone\Exceptions\APIException;
use HumanTone\Exceptions\AuthenticationException;
use HumanTone\Exceptions\DailyLimitExceededException;
use HumanTone\Exceptions\NotFoundException;
use HumanTone\Exceptions\RateLimitException;
use HumanTone\Internal\HttpConfig;
use HumanTone\Internal\HttpTransport;
use HumanTone\Internal\RetryPolicy;
use HumanTone\Tests\Support\FixedJitterSource;
use HumanTone\Tests\Support\Psr18MockClient;
use HumanTone\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class HttpTransportTest extends TestCase
{
    private const API_KEY = 'ht_' . '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private function transport(
        Psr18MockClient $client,
        int $maxRetries = 2,
        bool $retryOnPost = false,
        ?RecordingSleeper $sleeper = null,
    ): HttpTransport {
        $factory = new HttpFactory();
        $sleeper ??= new RecordingSleeper();
        return new HttpTransport(
            http: $client,
            requestFactory: $factory,
            streamFactory: $factory,
            retryPolicy: new RetryPolicy($maxRetries, $retryOnPost, new FixedJitterSource(0)),
            sleeper: $sleeper,
            config: new HttpConfig(
                apiKey: self::API_KEY,
                baseUrl: 'https://api.humantone.io',
                userAgent: 'humantone-php/0.0.1 (php/8.3.1)',
                timeout: 120.0,
                maxRetries: $maxRetries,
                retryOnPost: $retryOnPost,
            ),
        );
    }

    private static function jsonResponse(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], $body);
    }

    private static function passthroughDecoder(): \Closure
    {
        return static fn (array $body): array => $body;
    }

    // ---------- Header construction (§4.2) ----------

    public function testAuthorizationHeaderBearerToken(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(200, '{"ai_score": 50}')]);
        $t = $this->transport($client);
        $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());

        $req = $client->lastRequest();
        $this->assertNotNull($req);
        $this->assertSame('Bearer ' . self::API_KEY, $req->getHeaderLine('Authorization'));
    }

    public function testAcceptHeaderAlwaysSet(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(200, '{}'),
            self::jsonResponse(200, '{"ai_score": 50}'),
        ]);
        $t = $this->transport($client);
        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
        $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());

        $this->assertSame('application/json', $client->sent[0]->getHeaderLine('Accept'));
        $this->assertSame('application/json', $client->sent[1]->getHeaderLine('Accept'));
    }

    public function testContentTypeOnPostOnly(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(200, '{}'),
            self::jsonResponse(200, '{"ai_score": 50}'),
        ]);
        $t = $this->transport($client);
        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
        $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());

        $this->assertSame('', $client->sent[0]->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $client->sent[1]->getHeaderLine('Content-Type'));
    }

    public function testUserAgentHeaderUsesConfiguredValue(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(200, '{}')]);
        $t = $this->transport($client);
        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());

        $this->assertSame(
            'humantone-php/0.0.1 (php/8.3.1)',
            $client->lastRequest()?->getHeaderLine('User-Agent'),
        );
    }

    public function testJsonBodyEncoded(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(200, '{"ai_score": 50}')]);
        $t = $this->transport($client);
        $t->send('POST', '/v1/detect', ['content' => 'sample text'], 'detect', self::passthroughDecoder());

        $req = $client->lastRequest();
        $this->assertNotNull($req);
        $this->assertSame('{"content":"sample text"}', (string) $req->getBody());
    }

    public function testBaseUrlAndPathAssembled(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(200, '{}')]);
        $t = $this->transport($client);
        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());

        $this->assertSame('https://api.humantone.io/v1/account', (string) $client->lastRequest()?->getUri());
    }

    // ---------- Retry wiring per §7.2 ----------

    public function testGet5xxRetriesAndEventuallySucceeds(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(500, '{"error":"x"}'),
            self::jsonResponse(500, '{"error":"x"}'),
            self::jsonResponse(200, '{}'),
        ]);
        $sleeper = new RecordingSleeper();
        $t = $this->transport($client, sleeper: $sleeper);

        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
        $this->assertCount(3, $client->sent);
        $this->assertCount(2, $sleeper->calls);
    }

    public function testGet5xxExhaustedThrowsApiException(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(500, '{"error":"x"}'),
            self::jsonResponse(500, '{"error":"x"}'),
            self::jsonResponse(500, '{"error":"x"}'),
        ]);
        $t = $this->transport($client);

        $this->expectException(APIException::class);
        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
    }

    public function testPost5xxNoRetryByDefault(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(500, '{"error":"x"}')]);
        $t = $this->transport($client);

        try {
            $t->send('POST', '/v1/humanize', ['content' => 'x'], 'humanize', self::passthroughDecoder());
            $this->fail('expected');
        } catch (APIException) {
            $this->assertCount(1, $client->sent);
        }
    }

    public function testPost5xxRetriesWhenRetryOnPostTrue(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(500, '{"error":"x"}'),
            self::jsonResponse(500, '{"error":"x"}'),
            self::jsonResponse(200, '{"content":"ok","output_format":"text","credits_used":1,"request_id":"r"}'),
        ]);
        $t = $this->transport($client, retryOnPost: true);

        $body = $t->send('POST', '/v1/humanize', ['content' => 'x'], 'humanize', self::passthroughDecoder());
        $this->assertSame('ok', $body['content']);
        $this->assertCount(3, $client->sent);
    }

    public function test429AlwaysRetriesEvenOnPost(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(429, '{}', ['Retry-After' => '0']),
            self::jsonResponse(200, '{"ai_score":50}'),
        ]);
        $sleeper = new RecordingSleeper();
        $t = $this->transport($client, sleeper: $sleeper);

        $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());
        $this->assertCount(2, $client->sent);
        $this->assertCount(1, $sleeper->calls);
    }

    public function test429RespectsRetryAfterHeaderInSleeperCall(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(429, '{}', ['Retry-After' => '3']),
            self::jsonResponse(200, '{"ai_score":50}'),
        ]);
        $sleeper = new RecordingSleeper();
        $t = $this->transport($client, sleeper: $sleeper);

        $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());
        $this->assertSame(3000, $sleeper->calls[0]);
    }

    public function test429ExhaustedThrowsRateLimitException(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(429, '{}', ['Retry-After' => '0']),
            self::jsonResponse(429, '{}', ['Retry-After' => '0']),
            self::jsonResponse(429, '{}', ['Retry-After' => '0']),
        ]);
        $t = $this->transport($client);

        $this->expectException(RateLimitException::class);
        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
    }

    public function test4xxOtherNeverRetries(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(404, '{"error":"Not Found"}')]);
        $t = $this->transport($client);

        try {
            $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
            $this->fail('expected');
        } catch (NotFoundException) {
            $this->assertCount(1, $client->sent);
        }
    }

    public function testDetectSuccessFalseTransientRetries(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(200, '{"success": false}'),
            self::jsonResponse(200, '{"ai_score":50}'),
        ]);
        $t = $this->transport($client);

        $body = $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());
        $this->assertSame(50, $body['ai_score']);
        $this->assertCount(2, $client->sent);
    }

    public function testDetectDailyLimitDoesNotRetry(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(
            200,
            '{"success": false, "error": "Daily usage limit reached.", "time_to_next_renew": 3600}',
        )]);
        $t = $this->transport($client);

        try {
            $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());
            $this->fail('expected');
        } catch (DailyLimitExceededException) {
            $this->assertCount(1, $client->sent);
        }
    }

    public function testHumanize200SuccessFalseDoesNotRetryByDefault(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(200, '{"success": false, "error":"reserved"}'),
        ]);
        $t = $this->transport($client);

        try {
            $t->send('POST', '/v1/humanize', ['content' => 'x'], 'humanize', self::passthroughDecoder());
            $this->fail('expected');
        } catch (APIException) {
            $this->assertCount(1, $client->sent);
        }
    }

    public function testHumanize200SuccessFalseRetriesWhenRetryOnPost(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(200, '{"success": false, "error":"reserved"}'),
            self::jsonResponse(200, '{"content":"ok","output_format":"text","credits_used":1,"request_id":"r"}'),
        ]);
        $t = $this->transport($client, retryOnPost: true);

        $body = $t->send('POST', '/v1/humanize', ['content' => 'x'], 'humanize', self::passthroughDecoder());
        $this->assertSame('ok', $body['content']);
    }

    public function testParseFailureOn5xxRetriesForGet(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(500, '<html>nope</html>'),
            self::jsonResponse(200, '{}'),
        ]);
        $t = $this->transport($client);

        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
        $this->assertCount(2, $client->sent);
    }

    public function testParseFailureOn4xxDoesNotRetry(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(400, '<html>nope</html>')]);
        $t = $this->transport($client);

        try {
            $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
            $this->fail('expected');
        } catch (APIException) {
            $this->assertCount(1, $client->sent);
        }
    }

    public function testCoercionFailureFromDecoderDoesNotRetryOn2xx(): void
    {
        // Decoder throws APIException with coercion_error details. Status 200 is
        // non-5xx, so retry policy says NO retry.
        $coercingDecoder = static function (array $body): array {
            throw new APIException(
                message: 'Malformed response from HumanTone API. See exception details.',
                statusCode: 200,
                errorCode: 'api_error',
                details: ['raw_body' => '...', 'coercion_error' => 'ai_score: not int'],
            );
        };
        $client = new Psr18MockClient([self::jsonResponse(200, '{"ai_score":"bad"}')]);
        $t = $this->transport($client);

        try {
            $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', $coercingDecoder);
            $this->fail('expected');
        } catch (APIException) {
            $this->assertCount(1, $client->sent);
        }
    }

    public function testAuthenticationExceptionPropagatedFor401(): void
    {
        $client = new Psr18MockClient([self::jsonResponse(401, '{"error":"Invalid API key"}')]);
        $t = $this->transport($client);

        $this->expectException(AuthenticationException::class);
        $t->send('GET', '/v1/account', null, 'account', self::passthroughDecoder());
    }

    public function testDecoderReceivesParsedBodyAndReturnsItsValue(): void
    {
        $client = new Psr18MockClient([
            self::jsonResponse(200, '{"content":"hi","output_format":"text","credits_used":2,"request_id":"r"}'),
        ]);
        $t = $this->transport($client);

        $result = $t->send(
            'POST',
            '/v1/humanize',
            ['content' => 'x'],
            'humanize',
            static fn (array $body): string => 'decoded:' . $body['content'],
        );
        $this->assertSame('decoded:hi', $result);
    }

    public function testPsr18ClientExceptionMappedToNetworkExceptionForGenericErrors(): void
    {
        // Custom non-Guzzle PSR-18 NetworkException → maps to our NetworkException per §7.6.1.
        // Tested on POST (no retry by default) so a single queued exception is enough.
        $exc = new class ('boom') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new \RuntimeException('not used in this test');
            }
        };
        $client = new Psr18MockClient([$exc]);
        $t = $this->transport($client);

        try {
            $t->send('POST', '/v1/detect', ['content' => 'x'], 'detect', self::passthroughDecoder());
            $this->fail('expected');
        } catch (\HumanTone\Exceptions\NetworkException $e) {
            // expected — non-Guzzle NetworkExceptionInterface conservatively maps
            // to NetworkException per §7.6.1.
            $this->assertSame('boom', $e->getMessage());
        }
    }
}
