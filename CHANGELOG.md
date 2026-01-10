# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
