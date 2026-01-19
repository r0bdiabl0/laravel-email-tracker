# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-01-19

### Removed
- Unused `EMAIL_TRACKER_NOTIFICATION_CHANNEL` config option (notification channel works without it)
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
