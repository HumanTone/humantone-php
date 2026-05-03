# Changelog

All notable changes to `humantone/humantone-php` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.1] - 2026-05-01

### Added

- Initial release.
- `Client` with `humanize()`, `detect()`, and `account->get()` methods covering the three HumanTone REST endpoints.
- Full exception hierarchy: `AuthenticationException`, `PermissionException`, `RateLimitException`, `InsufficientCreditsException`, `DailyLimitExceededException`, `InvalidRequestException`, `NotFoundException`, `APIException`, `TimeoutException`, `NetworkException` — all extending `HumanToneException`.
- Eager API key validation in the constructor.
- Retry policy with exponential backoff and jitter; `Retry-After` header support (numeric and HTTP-date forms).
- PSR-18 HTTP client interface with Guzzle as the default; any compliant PSR-18 client can be injected.
- `HumanizationLevel` and `OutputFormat` string-backed enums.
- `HUMANTONE_API_KEY` and `HUMANTONE_BASE_URL` environment variable fallbacks.
- Configurable User-Agent suffix; default UA reports the SDK version and sanitized PHP version.
- Strict-typed `final readonly` DTOs (`HumanizeResult`, `DetectResult`, `AccountInfo`, `Plan`, `Credits`, `Subscription`) with `expires_at` parsed into `\DateTimeImmutable`.
- Forward-compatible parsing for both v1 (string `error`) and the planned v2 (object `error` with `code`, `message`, `details`) error response shapes.
