# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2] - 2026-01-22

### Fixed
- Fixed transport switching bug where failed mailer lookups would retain the previous request's transport instead of restoring the default, causing emails to be sent via the wrong provider
- Added error context logging when mailer initialization fails for easier debugging

## [1.4.1] - 2026-01-20

### Fixed
- Fixed type mismatch in `TrackedMailer::sendSymfonyMessage()` - now properly wraps Symfony's `SentMessage` in Laravel's `Illuminate\Mail\SentMessage`

## [1.4.0] - 2026-01-20

### Added
- **Resend and Postal API transports** - Emails now flow through `TrackedMailer` for all providers
- `ResendTransport` - Symfony transport for Resend HTTP API
- `PostalTransport` - Symfony transport for Postal HTTP API
- Automatic transport switching when using `EmailTracker::provider('resend')` or `provider('postal')`
- Full tracking support (opens, clicks, bounces, complaints) for Resend and Postal
- List-Unsubscribe headers now work for all providers (not just SES)

### Changed
- `provider()` method now switches the underlying transport to use the provider's API
- Resend and Postal emails get the same tracking features as SES (message tracking, headers, suppression)

### Migration Notes
To use Resend or Postal with full tracking support, configure them in your `config/mail.php`:

```php
'mailers' => [
    'resend' => [
        'transport' => 'resend',
        'key' => env('RESEND_API_KEY'),
    ],
    'postal' => [
        'transport' => 'postal',
        'url' => env('POSTAL_URL'),
        'key' => env('POSTAL_API_KEY'),
    ],
],
```

Then use the package normally:
```php
EmailTracker::provider('resend')->enableAllTracking()->to($user)->send($mailable);
```

## [1.3.2] - 2026-01-20

### Fixed
- Link tracking no longer converts `tel:` and `mailto:` links to tracking URLs
- LinkController now allows `tel:` and `mailto:` redirects for backwards compatibility with existing tracked links

## [1.3.1] - 2026-01-19

### Fixed
- Suppression now works when sending Mailables directly (was bypassed in v1.3.0)
- Provider is now passed correctly when checking suppression in `TracksWithEmail` trait

### Added
- `EmailValidator::isSuppressionEnabled()` helper method
- `EmailValidator::getBlockReason()` for efficient single-query suppression checks

### Changed
- Reduced duplicate database queries when checking suppression (2-4 queries down to 1-2)

## [1.3.0] - 2026-01-19

### Added
- Suppression now works automatically across all sending methods (Facade, TracksWithEmail trait, EmailTrackerChannel)
- `AddressSuppressedException` thrown when sending to suppressed addresses
- Better suppression reason reporting (permanent bounce, spam complaint)

### Changed
- Renamed config key `validation` to `suppression` for clarity
- Updated terminology from "validation" to "suppression" throughout codebase and documentation

### Removed
- Unused `tracking.*` config options (`opens`, `links`, `bounces`, `complaints`, `deliveries`) - use fluent API instead: `EmailTracker::enableAllTracking()`
- Unused SES SMTP options (`ping_threshold`, `restart_threshold`, `restart_sleep`)
- Unused `routes.middleware` config option

## [1.1.2] - 2026-01-19

### Removed
- Unused `EMAIL_TRACKER_NOTIFICATION_CHANNEL` config option (notification channel works without it)

## [1.1.1] - 2026-01-19

### Changed
- Documented all environment variables in README

## [1.1.0] - 2026-01-19

### Added
- RFC 8058 one-click unsubscribe support with `List-Unsubscribe` and `List-Unsubscribe-Post` headers
- `UnsubscribeController` for handling unsubscribe requests with signed URL validation
- `EmailUnsubscribeEvent` fired when a user unsubscribes
- `enableUnsubscribeHeaders()` and `disableUnsubscribeHeaders()` methods on TrackingTrait
- Configurable unsubscribe options: mailto fallback, signature expiration, redirect URL
- `provider()` method for explicit provider selection in multi-provider setups

### Fixed
- Custom provider registration now works correctly
- Default provider configuration is properly applied
- Base event handlers added for custom providers

### Changed
- Improved custom provider webhook setup documentation

## [1.0.0] - 2024-01-01

### Added
- Multi-provider support for AWS SES, Resend, Postal, Mailgun, SendGrid, and Postmark
- Configurable table prefix (or no prefix) for database tables
- Provider column in all tables to track which email provider was used
- Dynamic webhook routes (`/webhook/{provider}` pattern)
- Laravel 11, 12, and 13 compatibility
- Modern PHP 8.2+ features (enums, readonly DTOs, match expressions)
- `TracksWithEmail` trait for Mailables
- `EmailTrackerChannel` for notifications
- `EmailValidator` service for bounce/complaint checking
- Migration command from juhasev/laravel-ses (`php artisan email-tracker:migrate-from-ses`)
- Install command (`php artisan email-tracker:install`)
- Legacy route support for backwards compatibility
- `SesMail` facade alias for backwards compatibility

### Changed
- Namespace changed from `Juhasev\LaravelSes` to `R0bdiabl0\EmailTracker`
- Default table names no longer have `laravel_ses_` prefix
- Service provider follows Laravel 11+ best practices
- Uses `publishesMigrations()` for automatic timestamp updates

### Migration from juhasev/laravel-ses
- See [UPGRADE.md](UPGRADE.md) for detailed migration instructions
- Use `php artisan email-tracker:migrate-from-ses` for automatic migration
