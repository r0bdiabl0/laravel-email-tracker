# Laravel Email Tracker

[![Latest Version on Packagist](https://img.shields.io/packagist/v/r0bdiabl0/laravel-email-tracker.svg?style=flat-square)](https://packagist.org/packages/r0bdiabl0/laravel-email-tracker)
[![Total Downloads](https://img.shields.io/packagist/dt/r0bdiabl0/laravel-email-tracker.svg?style=flat-square)](https://packagist.org/packages/r0bdiabl0/laravel-email-tracker)
[![License](https://img.shields.io/packagist/l/r0bdiabl0/laravel-email-tracker.svg?style=flat-square)](https://packagist.org/packages/r0bdiabl0/laravel-email-tracker)

A multi-provider email tracking package for Laravel 11+ that tracks opens, clicks, bounces, complaints, and deliveries across **AWS SES, Resend, Postal, Mailgun, SendGrid, Postmark**, and more.

## Features

- **Multi-Provider Support** - Track emails from multiple providers in a unified way
- **Email Opens** - Track when recipients open emails (via tracking pixel)
- **Link Clicks** - Track when recipients click links in emails
- **Bounces** - Automatically track bounced emails (hard and soft)
- **Complaints** - Track spam complaints
- **Deliveries** - Confirm successful email deliveries
- **Batch Grouping** - Group emails into batches for analytics
- **Configurable Tables** - Customize table names with prefixes
- **Laravel 11, 12, 13 Ready** - Built with modern Laravel best practices
- **Optional Features** - Traits and notification channels are opt-in

## Requirements

- PHP 8.2+
- Laravel 11.0+

## Installation

```bash
composer require r0bdiabl0/laravel-email-tracker
```

Run the install command:

```bash
php artisan email-tracker:install
```

This will:
1. Publish the configuration file
2. Publish the migrations
3. Optionally run the migrations

## Configuration

The configuration file is published to `config/email-tracker.php`.

### Table Prefix

By default, tables are created without a prefix. You can customize this:

```php
// config/email-tracker.php
'table_prefix' => env('EMAIL_TRACKER_TABLE_PREFIX', ''),

// With prefix 'tracker_':
// tracker_sent_emails, tracker_email_bounces, etc.
```

### Enable Providers

Enable only the providers you use:

```php
'providers' => [
    'ses' => [
        'enabled' => env('EMAIL_TRACKER_SES_ENABLED', true),
        // ...
    ],
    'resend' => [
        'enabled' => env('EMAIL_TRACKER_RESEND_ENABLED', false),
        // ...
    ],
    // ...
],
```

## Basic Usage

### Sending Tracked Emails

```php
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

// Enable all tracking
EmailTracker::enableAllTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// With batch grouping
EmailTracker::enableAllTracking()
    ->setBatch('welcome-campaign')
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// Enable specific tracking only
EmailTracker::enableOpenTracking()
    ->enableLinkTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));
```

### Using the TracksWithEmail Trait (Optional)

Add the trait to your Mailable for convenience methods:

```php
use Illuminate\Mail\Mailable;
use R0bdiabl0\EmailTracker\Traits\TracksWithEmail;

class WelcomeMail extends Mailable
{
    use TracksWithEmail;

    // ...
}

// Then use:
WelcomeMail::sendTracked('user@example.com', batch: 'welcome');
WelcomeMail::queueTracked(['user@example.com'], batch: 'welcome', queue: 'emails');
```

### Using the Notification Channel (Optional)

```php
use Illuminate\Notifications\Notification;
use R0bdiabl0\EmailTracker\Notifications\EmailTrackerChannel;

class WelcomeNotification extends Notification
{
    public function via($notifiable): array
    {
        return [EmailTrackerChannel::class];
    }

    public function toEmailTracker($notifiable): Mailable
    {
        return new WelcomeMail($notifiable);
    }
}
```

## Webhook Setup

### AWS SES

Set up SNS topics for bounce, complaint, and delivery notifications:

```
POST https://your-app.com/email-tracker/webhook/ses/bounce
POST https://your-app.com/email-tracker/webhook/ses/complaint
POST https://your-app.com/email-tracker/webhook/ses/delivery
```

### Other Providers

```
POST https://your-app.com/email-tracker/webhook/resend
POST https://your-app.com/email-tracker/webhook/postal
POST https://your-app.com/email-tracker/webhook/mailgun
POST https://your-app.com/email-tracker/webhook/sendgrid
POST https://your-app.com/email-tracker/webhook/postmark
```

## Events

The package dispatches events for all tracking actions:

- `EmailSentEvent` - When an email is sent
- `EmailDeliveryEvent` - When delivery is confirmed
- `EmailBounceEvent` - When an email bounces
- `EmailComplaintEvent` - When a complaint is received
- `EmailOpenEvent` - When an email is opened
- `EmailLinkClickEvent` - When a link is clicked

Listen to these events in your `EventServiceProvider`:

```php
protected $listen = [
    \R0bdiabl0\EmailTracker\Events\EmailBounceEvent::class => [
        \App\Listeners\HandleEmailBounce::class,
    ],
];
```

## Email Validation

Automatically skip sending to bounced or complained addresses:

```php
// config/email-tracker.php
'validation' => [
    'skip_bounced' => true,
    'skip_complained' => true,
],
```

Or use the validator manually:

```php
use R0bdiabl0\EmailTracker\Services\EmailValidator;

if (EmailValidator::shouldBlock('user@example.com')) {
    // Don't send
}

$bounceCount = EmailValidator::getBounceCount('user@example.com');
$hasComplaint = EmailValidator::hasComplaint('user@example.com');
```

## Migrating from juhasev/laravel-ses

If you're migrating from `juhasev/laravel-ses`, use the migration command:

```bash
# Preview changes
php artisan email-tracker:migrate-from-ses --dry-run

# Run migration with backup
php artisan email-tracker:migrate-from-ses --backup

# Also update code namespaces
php artisan email-tracker:migrate-from-ses --backup --update-code
```

See [UPGRADE.md](UPGRADE.md) for detailed migration instructions.

### Backwards Compatibility

The `SesMail` facade is aliased to `EmailTracker` for backwards compatibility:

```php
// Still works!
SesMail::enableAllTracking()->to($email)->send($mailable);
```

Enable legacy routes to keep old webhook URLs working:

```env
EMAIL_TRACKER_LEGACY_ROUTES=true
```

## Database Schema

The package creates the following tables (with optional prefix):

- `sent_emails` - Records of all sent emails
- `email_opens` - Open tracking records
- `email_bounces` - Bounce records
- `email_complaints` - Complaint records
- `email_links` - Link click tracking
- `batches` - Batch groupings

All tables include a `provider` column to track which email provider was used.

## Statistics

Query email statistics:

```php
use R0bdiabl0\EmailTracker\Models\SentEmail;

// Get all bounced emails
$bounced = SentEmail::bounced()->get();

// Get emails by provider
$sesEmails = SentEmail::forProvider('ses')->get();

// Get emails by batch
$batch = Batch::where('name', 'campaign-2024')->first();
$batchEmails = $batch->sentEmails;
```

## Extending

### Custom Providers

Implement the `EmailProviderInterface` and register your provider:

```php
use R0bdiabl0\EmailTracker\Contracts\EmailProviderInterface;
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

class CustomProvider implements EmailProviderInterface
{
    // Implement required methods...
}

// Register in a service provider
EmailTracker::registerProvider('custom', CustomProvider::class);
```

### Custom Models

Override default models in the config:

```php
'models' => [
    'sent_email' => \App\Models\SentEmail::class,
    // ...
],
```

## Testing

```bash
composer test
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Credits

This package is based on the excellent work from [juhasev/laravel-ses](https://github.com/juhasev/laravel-ses).
