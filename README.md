# humantone/humantone-php

Official PHP SDK for [HumanTone](https://humantone.io). Humanize AI-generated text and check AI likelihood from your PHP code. One API key, same credits you already use in the HumanTone web app.

## Install

```bash
composer require humantone/humantone-php
```

Requires PHP 8.2 or later.

## Requirements

- PHP 8.2+
- A paid HumanTone plan with API access. Free trial accounts cannot use the API.
- An API key from [app.humantone.io/settings/api](https://app.humantone.io/settings/api).

## Quickstart

```php
<?php

require 'vendor/autoload.php';

use HumanTone\Client;

$client = new Client(apiKey: getenv('HUMANTONE_API_KEY'));

$result = $client->humanize(
    text: 'Your AI-generated draft goes here. At least 30 words for the API to accept it.',
);

echo $result->text;
echo "Credits used: {$result->creditsUsed}\n";
```

The client also picks up `HUMANTONE_API_KEY` from the environment automatically:

```php
$client = new Client(); // reads HUMANTONE_API_KEY from env
```

## What's included

The SDK exposes three methods that map 1:1 to the three HumanTone API endpoints.

### `$client->humanize(...)`

Rewrites AI-generated text to sound more natural.

| Argument | Type | Default | Notes |
|---|---|---|---|
| `text` | `string` | required | Min 30 words. Max depends on plan (Basic 750, Standard 1000, Pro 1500). |
| `level` | `HumanizationLevel` | `Standard` | `Advanced` and `Extreme` are English-only. |
| `outputFormat` | `OutputFormat` | `Text` | SDK default is `Text` even though the API default is `Html`. |
| `customInstructions` | `?string` | `null` | Free-form rewrite guidance. Max 1000 chars. |

Returns `HumanizeResult` with `$text`, `$outputFormat`, `$creditsUsed`, `$requestId` (nullable string).

```php
$result = $client->humanize(
    text: '...',
    level: HumanizationLevel::Standard,
    customInstructions: 'Keep a formal corporate tone.',
);
```

### `$client->detect(string $text): DetectResult`

Returns AI likelihood score 0-100. Higher means more AI-like patterns. Free, but limited to 30 calls per day per account (shared between the web app and the API).

```php
$score = $client->detect(text: '...');
echo $score->aiScore;
```

### `$client->account->get(): AccountInfo`

Returns plan, credit balance, and subscription status. Useful for checking remaining credits before a large batch.

```php
$info = $client->account->get();
echo $info->plan->name;
echo $info->credits->total;
echo $info->plan->maxWords;
echo $info->subscription->active ? 'active' : 'inactive';
echo $info->subscription->expiresAt?->format('Y-m-d') ?? 'unknown';
```

## Configuration

```php
$client = new Client(
    apiKey: 'ht_...',                             // or HUMANTONE_API_KEY env
    baseUrl: 'https://api.humantone.io',          // or HUMANTONE_BASE_URL env, default is api.humantone.io
    timeout: 120.0,                                // seconds
    maxRetries: 2,
    retryOnPost: false,                            // POST endpoints retry only when explicit
    userAgent: 'my-app/1.0',                       // appended to default UA after a single space
);
```

You can inject any PSR-18 HTTP client:

```php
use HumanTone\Client;
use Symfony\Component\HttpClient\Psr18Client as SymfonyClient;

$client = new Client(
    apiKey: 'ht_...',
    httpClient: new SymfonyClient(),
);
```

When you inject a non-Guzzle PSR-18 client, the SDK can no longer distinguish a transport-level timeout from a generic network failure. Both are surfaced as `NetworkException`. Configure timeouts on your injected client. Guzzle (the default) maps connect-phase timeouts and `CURLE_OPERATION_TIMEDOUT` to `TimeoutException`.

## Error handling

All exceptions raised by the SDK extend `HumanToneException`. Catch specific subclasses for expected cases.

```php
use HumanTone\Client;
use HumanTone\Exceptions\HumanToneException;
use HumanTone\Exceptions\InsufficientCreditsException;
use HumanTone\Exceptions\DailyLimitExceededException;
use HumanTone\Exceptions\InvalidRequestException;
use HumanTone\Exceptions\AuthenticationException;
use HumanTone\Exceptions\RateLimitException;

$client = new Client();

try {
    $result = $client->humanize(text: '...');
} catch (InsufficientCreditsException) {
    echo "Buy more credits at https://app.humantone.io/settings/credits\n";
} catch (RateLimitException $e) {
    echo "Rate limited. Retry in {$e->getRetryAfterSeconds()}s.\n";
} catch (InvalidRequestException $e) {
    echo "Bad input: {$e->getMessage()}\n";
} catch (AuthenticationException) {
    echo "Check your API key.\n";
} catch (HumanToneException $e) {
    echo "HumanTone API error ({$e->getErrorCode()}): {$e->getMessage()}\n";
    if ($e->getRequestId()) {
        echo "Request ID: {$e->getRequestId()}\n";
    }
}
```

Every exception exposes:

- `getMessage(): string`
- `getStatusCode(): ?int`
- `getRequestId(): ?string`
- `getErrorCode(): ?string`
- `getDetails(): ?array`
- `isRetryable(): bool`

Specific exceptions add typed accessors: `RateLimitException::getRetryAfterSeconds(): int`, `DailyLimitExceededException::getTimeToNextRenew(): ?int`, `InsufficientCreditsException::getRequiredCredits(): ?int` and `getAvailableCredits(): ?int`.

## Retry behavior

The SDK retries `account.get()` on network errors, 5xx, and 429 (up to 2 retries). POST methods (`humanize`, `detect`) do **not** retry on network or 5xx by default. Humanize debits credits, so a retried request risks double-billing. Set `retryOnPost: true` to opt in. 429 always retries on every method.

`Retry-After` headers are honored in both numeric (seconds) and HTTP-date formats.

## Limits to remember

- **Per-request word limit.** Basic 750, Standard 1000, Pro 1500. Inputs must be at least 30 words.
- **Credits.** Humanize consumes 1 credit per 100 words. Account checks and AI likelihood checks do not consume credits.
- **AI likelihood quota.** 30 checks per day per account, shared between the HumanTone web app and any API or SDK usage. Resets at midnight UTC.
- **API access.** Included on all paid plans. Free trial accounts cannot use the API.

## Links

- API docs: https://humantone.io/docs/api/
- MCP server: https://humantone.io/docs/mcp/
- Get an API key: https://app.humantone.io/settings/api
- Manage plan and credits: https://app.humantone.io/settings/plan, https://app.humantone.io/settings/credits
- Issues: https://github.com/humantone/humantone-php/issues
- Author email: dev@humantone.io
- Product support: help@humantone.io

## License

MIT. Copyright (c) HumanTone.
