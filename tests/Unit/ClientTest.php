<?php

declare(strict_types=1);

namespace HumanTone\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use HumanTone\Client;
use HumanTone\Enums\HumanizationLevel;
use HumanTone\Enums\OutputFormat;
use HumanTone\Exceptions\InvalidRequestException;
use HumanTone\Tests\Support\Psr18MockClient;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const VALID_KEY = 'ht_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const ANOTHER_KEY = 'ht_fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['HUMANTONE_API_KEY', 'HUMANTONE_BASE_URL'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name); // unset
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("$name=$value");
            }
        }
    }

    private function jsonResponse(int $status, string $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], $body);
    }

    // ---------- API key validation (§5.3) ----------

    public function testMissingApiKeyAndEnvThrowsMissingApiKey(): void
    {
        try {
            new Client();
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('missing_api_key', $e->getErrorCode());
            $this->assertSame(
                'Missing API key. Pass apiKey to HumanTone\Client or set HUMANTONE_API_KEY environment variable. Get a key at https://humantone.io/dashboard/settings/api',
                $e->getMessage(),
            );
        }
    }

    public function testEmptyApiKeyConstructorArgIsTreatedAsMissing(): void
    {
        try {
            new Client(apiKey: '');
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('missing_api_key', $e->getErrorCode());
        }
    }

    public function testWhitespaceApiKeyConstructorArgIsTreatedAsMissing(): void
    {
        try {
            new Client(apiKey: '   ');
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('missing_api_key', $e->getErrorCode());
        }
    }

    public function testEmptyApiKeyEnvIsTreatedAsMissing(): void
    {
        putenv('HUMANTONE_API_KEY=');
        try {
            new Client();
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('missing_api_key', $e->getErrorCode());
        }
    }

    public function testWhitespaceApiKeyEnvIsTreatedAsMissing(): void
    {
        putenv('HUMANTONE_API_KEY=   ');
        try {
            new Client();
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('missing_api_key', $e->getErrorCode());
        }
    }

    public function testMalformedApiKeyConstructorArgThrowsInvalidFormat(): void
    {
        try {
            new Client(apiKey: 'ht_xyz');
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('invalid_api_key_format', $e->getErrorCode());
            $this->assertSame(
                'Invalid API key format. Expected ht_ prefix followed by 64 hex characters.',
                $e->getMessage(),
            );
        }
    }

    public function testMalformedApiKeyEnvThrowsInvalidFormat(): void
    {
        putenv('HUMANTONE_API_KEY=not_an_ht_key');
        try {
            new Client();
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('invalid_api_key_format', $e->getErrorCode());
        }
    }

    public function testApiKeyWithUppercaseHexRejected(): void
    {
        $upper = 'ht_0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF';
        try {
            new Client(apiKey: $upper);
            $this->fail('expected');
        } catch (InvalidRequestException $e) {
            $this->assertSame('invalid_api_key_format', $e->getErrorCode());
        }
    }

    public function testApiKeyTrimmedAcrossWhitespace(): void
    {
        $client = new Client(
            apiKey: '  ' . self::VALID_KEY . '  ',
            httpClient: new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 50}')]),
        );
        $this->assertSame(50, $client->detect('a draft')->aiScore);
    }

    public function testValidApiKeyEnvAccepted(): void
    {
        putenv('HUMANTONE_API_KEY=' . self::VALID_KEY);
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 99}')]);
        $client = new Client(httpClient: $mock);
        $this->assertSame(99, $client->detect('x')->aiScore);
        $this->assertSame('Bearer ' . self::VALID_KEY, $mock->lastRequest()?->getHeaderLine('Authorization'));
    }

    public function testConstructorArgOverridesEnvForApiKey(): void
    {
        putenv('HUMANTONE_API_KEY=' . self::VALID_KEY);
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 50}')]);
        $client = new Client(apiKey: self::ANOTHER_KEY, httpClient: $mock);
        $client->detect('x');
        $this->assertSame('Bearer ' . self::ANOTHER_KEY, $mock->lastRequest()?->getHeaderLine('Authorization'));
    }

    // ---------- baseUrl resolution (§5.2) ----------

    public function testDefaultBaseUrlIsHumantoneIo(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 50}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->detect('x');
        $this->assertSame('https://api.humantone.io/v1/detect', (string) $mock->lastRequest()?->getUri());
    }

    public function testBaseUrlEnvUsed(): void
    {
        putenv('HUMANTONE_BASE_URL=https://staging.humantone.io');
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 50}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->detect('x');
        $this->assertSame('https://staging.humantone.io/v1/detect', (string) $mock->lastRequest()?->getUri());
    }

    public function testConstructorArgOverridesBaseUrlEnv(): void
    {
        putenv('HUMANTONE_BASE_URL=https://staging.humantone.io');
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 50}')]);
        $client = new Client(
            apiKey: self::VALID_KEY,
            baseUrl: 'https://override.example.com',
            httpClient: $mock,
        );
        $client->detect('x');
        $this->assertSame('https://override.example.com/v1/detect', (string) $mock->lastRequest()?->getUri());
    }

    public function testEmptyBaseUrlEnvFallsBackToDefault(): void
    {
        putenv('HUMANTONE_BASE_URL=');
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 50}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->detect('x');
        $this->assertSame('https://api.humantone.io/v1/detect', (string) $mock->lastRequest()?->getUri());
    }

    public function testWhitespaceBaseUrlEnvFallsBackToDefault(): void
    {
        putenv('HUMANTONE_BASE_URL=   ');
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 50}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->detect('x');
        $this->assertSame('https://api.humantone.io/v1/detect', (string) $mock->lastRequest()?->getUri());
    }

    // ---------- humanize ----------

    public function testHumanizeHappyPathRenamesContentToText(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(
            200,
            '{"success":true,"request_id":"r1","content":"humanized!","output_format":"text","credits_used":5}',
        )]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);

        $result = $client->humanize('Some long enough draft text');
        $this->assertSame('humanized!', $result->text);
        $this->assertSame(OutputFormat::Text, $result->outputFormat);
        $this->assertSame(5, $result->creditsUsed);
        $this->assertSame('r1', $result->requestId);
    }

    public function testHumanizeDefaultOutputFormatTextInOutgoingBody(): void
    {
        // §14 acceptance: "Default outputFormat: Text verified in test that asserts request body".
        $mock = new Psr18MockClient([$this->jsonResponse(
            200,
            '{"content":"x","output_format":"text","credits_used":1,"request_id":"r"}',
        )]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->humanize('a draft text');
        $body = (string) $mock->lastRequest()?->getBody();
        $this->assertJsonStringEqualsJsonString(
            '{"content":"a draft text","humanization_level":"standard","output_format":"text"}',
            $body,
        );
    }

    public function testHumanizeAdvancedLevelSerializedCorrectly(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(
            200,
            '{"content":"x","output_format":"text","credits_used":1,"request_id":"r"}',
        )]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->humanize('text', HumanizationLevel::Advanced, OutputFormat::Markdown, 'tone:formal');
        $body = json_decode((string) $mock->lastRequest()?->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('advanced', $body['humanization_level']);
        $this->assertSame('markdown', $body['output_format']);
        $this->assertSame('tone:formal', $body['custom_instructions']);
    }

    // ---------- detect ----------

    public function testDetectHappyPath(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"success":true,"ai_score":42}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);

        $result = $client->detect('Some text to evaluate');
        $this->assertSame(42, $result->aiScore);
    }

    public function testDetectSendsContentNotText(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score":1}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->detect('the input');
        $this->assertSame('{"content":"the input"}', (string) $mock->lastRequest()?->getBody());
    }

    // ---------- account.get ----------

    public function testAccountGetHappyPath(): void
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/account_200.json');
        $this->assertNotFalse($body);
        $mock = new Psr18MockClient([$this->jsonResponse(200, $body)]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);

        $info = $client->account->get();
        $this->assertSame('pro_monthly', $info->plan->id);
        $this->assertSame(1500, $info->plan->maxWords);
        $this->assertSame(970, $info->credits->total);
        $this->assertTrue($info->subscription->active);
        $this->assertNotNull($info->subscription->expiresAt);
    }

    public function testAccountGetUsesGetMethod(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(200, file_get_contents(__DIR__ . '/../Fixtures/account_200.json') ?: '{}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->account->get();
        $this->assertSame('GET', $mock->lastRequest()?->getMethod());
        $this->assertSame('https://api.humantone.io/v1/account', (string) $mock->lastRequest()?->getUri());
    }

    // ---------- Smoke: error mapping wires through Client ----------

    public function testHumanize401PropagatesAsAuthenticationException(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(401, '{"error":"Invalid API key"}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);

        $this->expectException(\HumanTone\Exceptions\AuthenticationException::class);
        $client->humanize('x');
    }

    public function testHumanize400NotEnoughCreditsPropagatesAsInsufficientCreditsException(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(400, '{"error":"Not enough credits"}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);

        $this->expectException(\HumanTone\Exceptions\InsufficientCreditsException::class);
        $client->humanize('x');
    }

    public function testDetectDailyLimitPropagatesAsDailyLimitExceededException(): void
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/detect_daily_limit.json');
        $this->assertNotFalse($body);
        $mock = new Psr18MockClient([$this->jsonResponse(200, $body)]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);

        try {
            $client->detect('x');
            $this->fail('expected');
        } catch (\HumanTone\Exceptions\DailyLimitExceededException $e) {
            $this->assertSame(3600, $e->getTimeToNextRenew());
        }
    }

    public function testAccount404PropagatesAsNotFoundException(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(404, '{"error":"Plan not found"}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);

        $this->expectException(\HumanTone\Exceptions\NotFoundException::class);
        $client->account->get();
    }

    public function testUserAgentHeaderPresentOnAllRequests(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 1}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock);
        $client->detect('x');
        $ua = $mock->lastRequest()?->getHeaderLine('User-Agent') ?? '';
        $this->assertStringStartsWith('humantone-php/', $ua);
        $this->assertStringContainsString('(php/', $ua);
    }

    public function testCustomUserAgentSuffixAppended(): void
    {
        $mock = new Psr18MockClient([$this->jsonResponse(200, '{"ai_score": 1}')]);
        $client = new Client(apiKey: self::VALID_KEY, httpClient: $mock, userAgent: 'my-app/2.0');
        $client->detect('x');
        $ua = $mock->lastRequest()?->getHeaderLine('User-Agent') ?? '';
        $this->assertStringEndsWith(' my-app/2.0', $ua);
    }
}
