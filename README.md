# Laravel Email Tracker

[![Latest Version on Packagist](https://img.shields.io/packagist/v/r0bdiabl0/laravel-email-tracker.svg?style=flat-square)](https://packagist.org/packages/r0bdiabl0/laravel-email-tracker)
[![Total Downloads](https://img.shields.io/packagist/dt/r0bdiabl0/laravel-email-tracker.svg?style=flat-square)](https://packagist.org/packages/r0bdiabl0/laravel-email-tracker)
[![License](https://img.shields.io/packagist/l/r0bdiabl0/laravel-email-tracker.svg?style=flat-square)](https://packagist.org/packages/r0bdiabl0/laravel-email-tracker)

A **multi-provider email tracking and bounce management package** for Laravel 11+ that provides unified tracking for opens, clicks, bounces, complaints, and deliveries across **AWS SES, Resend, Postal, Mailgun, SendGrid, and Postmark**. Includes optional suppression to automatically skip sending to problematic addresses.

## Table of Contents

- [What This Package Does](#what-this-package-does)
- [What This Package Does NOT Do](#what-this-package-does-not-do)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Webhook Setup](#webhook-setup)
  - [AWS SES Setup](#aws-ses-setup)
  - [Resend Setup](#resend-setup)
  - [Mailgun Setup](#mailgun-setup)
  - [SendGrid Setup](#sendgrid-setup)
  - [Postmark Setup](#postmark-setup)
  - [Postal Setup](#postal-setup)
- [Security Considerations](#security-considerations)
- [One-Click Unsubscribe (RFC 8058)](#one-click-unsubscribe-rfc-8058)
- [Events](#events)
- [Suppression (Bounce Management)](#suppression-bounce-management)
- [Database Schema](#database-schema)
- [Querying Data](#querying-data)
- [Migrating from juhasev/laravel-ses](#migrating-from-juhasevlaravel-ses)
- [Admin Panel Plugins](#admin-panel-plugins)
- [Extending](#extending)
  - [Custom Providers](#custom-providers)
  - [Custom Models](#custom-models)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)
- [Credits](#credits)

## What This Package Does

- **Tracks Sent Emails** - Stores records of all emails sent through the package with their message IDs
- **Open Tracking** - Injects a 1x1 tracking pixel to detect when recipients open emails
- **Link Click Tracking** - Rewrites links to track when recipients click them, with click counts
- **Bounce Handling** - Receives and processes bounce notifications from email providers via webhooks
- **Complaint Handling** - Tracks spam complaints reported by recipients
- **Delivery Confirmation** - Records successful deliveries reported by email providers
- **Batch Grouping** - Organize emails into named batches for campaigns or bulk sends
- **Multi-Provider Support** - Unified interface across 6 major email providers
- **Suppression** - Optionally skip sending to previously bounced or complained addresses (bounce management)
- **One-Click Unsubscribe** - RFC 8058 compliant List-Unsubscribe headers for improved deliverability
- **Event Dispatching** - Laravel events for all tracking activities for your own listeners

## What This Package Does NOT Do

- **Does NOT send emails** - This package tracks emails sent via Laravel's mail system. You still need to configure Laravel Mail with your provider (SES, Mailgun, etc.)
- **Does NOT provide SMTP services** - You need your own email provider account
- **Does NOT guarantee open tracking accuracy** - Many email clients block tracking pixels. Open tracking should be considered a lower-bound estimate
- **Does NOT track replies** - This package tracks delivery events, not incoming mail
- **Does NOT provide analytics dashboards** - It stores data in your database; you build your own reports or use tools like Filament
- **Does NOT provide a template builder** - You design emails using Laravel's Mailable and Blade views (which are fully supported and tracked)
- **Does NOT replace your email provider's dashboard** - It supplements it with data in your own database

## Requirements

- PHP 8.2+
- Laravel 11.0+
- An email provider account (AWS SES, Resend, Postal, Mailgun, SendGrid, or Postmark)

## Installation

```bash
composer require r0bdiabl0/laravel-email-tracker
```

Run the install command:

```bash
php artisan email-tracker:install
```

This will:
1. Publish the configuration file to `config/email-tracker.php`
2. Publish the migrations
3. Optionally run the migrations

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
# =============================================================================
# CORE SETTINGS
# =============================================================================

# Default provider (ses, resend, postal, mailgun, sendgrid, postmark)
EMAIL_TRACKER_DEFAULT_PROVIDER=ses

# Optional table prefix (leave empty for no prefix)
EMAIL_TRACKER_TABLE_PREFIX=

# Enable/disable route registration
EMAIL_TRACKER_ROUTES_ENABLED=true

# Route prefix for all email tracker routes (default: email-tracker)
EMAIL_TRACKER_ROUTE_PREFIX=email-tracker

# Debug logging (disable in production)
EMAIL_TRACKER_DEBUG=false

# Log message prefix
EMAIL_TRACKER_LOG_PREFIX=EMAIL-TRACKER

# =============================================================================
# PROVIDER SETTINGS
# =============================================================================

# Enable/disable providers
EMAIL_TRACKER_SES_ENABLED=true
EMAIL_TRACKER_RESEND_ENABLED=false
EMAIL_TRACKER_POSTAL_ENABLED=false
EMAIL_TRACKER_MAILGUN_ENABLED=false
EMAIL_TRACKER_SENDGRID_ENABLED=false
EMAIL_TRACKER_POSTMARK_ENABLED=false

# AWS SES specific settings
EMAIL_TRACKER_SNS_VALIDATOR=true              # Validate SNS message signatures (recommended)

# Webhook signing secrets (provider-specific)
RESEND_WEBHOOK_SECRET=whsec_...               # Resend: Svix webhook signature
MAILGUN_WEBHOOK_SIGNING_KEY=key-...           # Mailgun: HMAC-SHA256 signing key
SENDGRID_VERIFICATION_KEY="-----BEGIN..."     # SendGrid: ECDSA public key (PEM format)
POSTAL_WEBHOOK_KEY=your-secret-key            # Postal: X-Postal-Webhook-Key header
POSTMARK_WEBHOOK_TOKEN=your-token             # Postmark: X-Postmark-Webhook-Token header

# =============================================================================
# SUPPRESSION / BOUNCE MANAGEMENT (disabled by default)
# =============================================================================

# Automatically skip sending to problematic addresses (recommended for production)
EMAIL_TRACKER_SKIP_BOUNCED=false              # Set true to suppress bounced addresses
EMAIL_TRACKER_SKIP_COMPLAINED=false           # Set true to suppress addresses that complained (spam)

# =============================================================================
# ONE-CLICK UNSUBSCRIBE (RFC 8058)
# =============================================================================

EMAIL_TRACKER_UNSUBSCRIBE_ENABLED=false       # Enable List-Unsubscribe headers
EMAIL_TRACKER_UNSUBSCRIBE_MAILTO=             # Optional mailto: fallback address
EMAIL_TRACKER_UNSUBSCRIBE_EXPIRATION=0        # Signature expiration in hours (0 = never)
EMAIL_TRACKER_UNSUBSCRIBE_REDIRECT=           # Redirect URL after unsubscribe (null = JSON)

# =============================================================================
# OTHER OPTIONS
# =============================================================================

# Enable legacy routes for backwards compatibility with juhasev/laravel-ses
EMAIL_TRACKER_LEGACY_ROUTES=false
```

### Table Names

By default, tables are created without a prefix:
- `sent_emails`
- `email_opens`
- `email_bounces`
- `email_complaints`
- `email_links`
- `batches`

With a prefix like `tracker`:
- `tracker_sent_emails`
- `tracker_email_bounces`
- etc.

### Enable Providers

Enable only the providers you use:

```php
// config/email-tracker.php
'providers' => [
    'ses' => [
        'enabled' => env('EMAIL_TRACKER_SES_ENABLED', true),
        'sns_validator' => true, // Validate SNS message signatures
    ],
    'resend' => [
        'enabled' => env('EMAIL_TRACKER_RESEND_ENABLED', false),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],
    'mailgun' => [
        'enabled' => env('EMAIL_TRACKER_MAILGUN_ENABLED', false),
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],
    // ... etc.
],
```

### Using Multiple Providers

You can enable multiple providers simultaneously and switch between them per-send:

```env
# Set your default provider
EMAIL_TRACKER_DEFAULT_PROVIDER=ses

# Enable multiple providers
EMAIL_TRACKER_SES_ENABLED=true
EMAIL_TRACKER_RESEND_ENABLED=true
EMAIL_TRACKER_MAILGUN_ENABLED=true
```

```php
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

// Uses the default provider (from EMAIL_TRACKER_DEFAULT_PROVIDER)
EmailTracker::enableAllTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// Override to use a specific provider for this send
EmailTracker::provider('resend')
    ->enableAllTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// Use Mailgun for transactional emails
EmailTracker::provider('mailgun')
    ->enableAllTracking()
    ->to('user@example.com')
    ->send(new OrderConfirmation($order));
```

Each provider has its own webhook endpoint. When you receive bounce/complaint notifications, they'll be routed to the correct handler based on the URL:
- SES: `POST /email-tracker/webhook/ses`
- Resend: `POST /email-tracker/webhook/resend`
- Mailgun: `POST /email-tracker/webhook/mailgun`
- etc.

The `provider` column in the database tracks which service sent each email, allowing you to query statistics by provider.

## Basic Usage

### Sending Tracked Emails

```php
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

// Enable all tracking (opens, links, bounces, complaints, deliveries)
// Note: This does NOT enable unsubscribe headers - use enableUnsubscribeHeaders() separately
EmailTracker::enableAllTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// With unsubscribe headers for bulk/marketing emails
EmailTracker::enableAllTracking()
    ->enableUnsubscribeHeaders()  // Add RFC 8058 List-Unsubscribe headers
    ->to('user@example.com')
    ->send(new MarketingMail($user));

// With batch grouping for campaigns
EmailTracker::enableAllTracking()
    ->setBatch('welcome-campaign-2024')
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// Enable specific tracking only
EmailTracker::enableOpenTracking()
    ->enableLinkTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// Specify provider explicitly
EmailTracker::provider('resend')
    ->enableAllTracking()
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

    public function build()
    {
        return $this->view('emails.welcome');
    }
}

// Static methods for quick sending
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

Your email provider will send event notifications (bounces, complaints, deliveries) to these webhook URLs. You must configure these URLs in each provider's dashboard.

### Webhook URLs

| Provider  | Webhook URL |
|-----------|-------------|
| AWS SES   | `https://your-app.com/email-tracker/webhook/ses/bounce`<br>`https://your-app.com/email-tracker/webhook/ses/complaint`<br>`https://your-app.com/email-tracker/webhook/ses/delivery` |
| Resend    | `https://your-app.com/email-tracker/webhook/resend` |
| Postal    | `https://your-app.com/email-tracker/webhook/postal` |
| Mailgun   | `https://your-app.com/email-tracker/webhook/mailgun` |
| SendGrid  | `https://your-app.com/email-tracker/webhook/sendgrid` |
| Postmark  | `https://your-app.com/email-tracker/webhook/postmark` |

### AWS SES Setup

1. Create SNS topics for bounces, complaints, and deliveries in AWS Console
2. Add HTTPS subscriptions pointing to your webhook URLs
3. Configure your SES domain/email to publish to these SNS topics
4. The package automatically validates SNS message signatures

```bash
# Example: Create SNS subscription via AWS CLI
aws sns subscribe \
  --topic-arn arn:aws:sns:us-east-1:123456789:ses-bounces \
  --protocol https \
  --notification-endpoint https://your-app.com/email-tracker/webhook/ses/bounce
```

### Resend Setup

1. Go to Resend Dashboard > Webhooks
2. Add a new webhook pointing to `https://your-app.com/email-tracker/webhook/resend`
3. Select events: `email.bounced`, `email.complained`, `email.delivered`
4. Copy the signing secret (starts with `whsec_`) to your `.env`

### Mailgun Setup

1. Go to Mailgun Dashboard > Sending > Webhooks
2. Add webhook URLs for Permanent Failures, Temporary Failures, and Delivered
3. Copy your webhook signing key to your `.env`

### SendGrid Setup

1. Go to SendGrid Dashboard > Settings > Mail Settings > Event Webhook
2. Set the HTTP POST URL to `https://your-app.com/email-tracker/webhook/sendgrid`
3. Select events: Bounced, Spam Reports, Delivered
4. Enable Event Webhook Security and copy the verification key

### Postmark Setup

1. Go to Postmark > Servers > Your Server > Webhooks
2. Add webhooks for Bounces, Spam Complaints, and Deliveries
3. Set the webhook URL and optionally configure Basic Auth for security

### Postal Setup

1. Go to your Postal server admin panel
2. Add a webhook endpoint pointing to `https://your-app.com/email-tracker/webhook/postal`
3. Configure the shared secret key in your `.env`

## Security Considerations

### Webhook Signature Validation

All providers support webhook signature validation to ensure requests are authentic:

| Provider  | Validation Method | Required Config |
|-----------|-------------------|-----------------|
| AWS SES   | SNS certificate validation | Automatic |
| Resend    | Svix HMAC-SHA256 | `webhook_secret` |
| Mailgun   | HMAC-SHA256 | `webhook_signing_key` |
| SendGrid  | ECDSA P-256 | `verification_key` |
| Postmark  | Header token or Basic Auth | `webhook_token` |
| Postal    | Header token | `webhook_key` |

**Important**: In development, validation is skipped if no secret is configured. In production, always configure your webhook secrets.

### Protecting Webhook Routes

The webhook routes are public by default (no auth middleware). This is required because email providers need to access them. Security is provided through signature validation.

If you need additional protection, you can:

1. Configure IP allowlists in your web server (nginx/Apache)
2. Add custom middleware in the config:

```php
// config/email-tracker.php
'routes' => [
    'middleware' => ['throttle:60,1'], // Rate limiting
],
```

### CSRF Protection

Webhook routes must be excluded from CSRF protection since they receive POST requests from external services. The package routes are loaded outside the `web` middleware group, but if your application applies CSRF middleware globally, you need to exclude the webhook routes.

Add to your `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'email-tracker/webhook/*',
    ]);
})
```

Or in `app/Http/Middleware/VerifyCsrfToken.php` (Laravel 10):

```php
protected $except = [
    'email-tracker/webhook/*',
];
```

## One-Click Unsubscribe (RFC 8058)

The package supports RFC 8058 compliant one-click unsubscribe headers, which are now required by Gmail, Yahoo, and other major email providers for bulk senders. This feature improves deliverability and helps you comply with sender requirements.

### How It Works

1. When enabled, the package adds `List-Unsubscribe` and `List-Unsubscribe-Post` headers to your emails
2. Email clients show an "Unsubscribe" button in their UI
3. When clicked, a POST request is sent to your app's signed unsubscribe endpoint
4. The package validates the signature and fires an `EmailUnsubscribeEvent`
5. **You handle the business logic** in your event listener

### Enabling Unsubscribe Headers

#### Option 1: Global (All Tracked Emails)

```env
EMAIL_TRACKER_UNSUBSCRIBE_ENABLED=true
```

#### Option 2: Per-Email

```php
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

EmailTracker::enableAllTracking()
    ->enableUnsubscribeHeaders()
    ->to('user@example.com')
    ->send(new NewsletterMail($user));
```

### Configuration

```php
// config/email-tracker.php
'unsubscribe' => [
    // Enable one-click unsubscribe headers globally
    'enabled' => env('EMAIL_TRACKER_UNSUBSCRIBE_ENABLED', false),

    // Optional: Include a mailto: fallback (some older clients prefer this)
    'mailto' => env('EMAIL_TRACKER_UNSUBSCRIBE_MAILTO'),

    // Signature expiration in hours (0 = no expiration)
    'signature_expiration' => env('EMAIL_TRACKER_UNSUBSCRIBE_EXPIRATION', 0),

    // Redirect URL after unsubscribe (null = return JSON response)
    'redirect_url' => env('EMAIL_TRACKER_UNSUBSCRIBE_REDIRECT'),
],
```

### Handling Unsubscribe Events

Register a listener for the `EmailUnsubscribeEvent`:

```php
use R0bdiabl0\EmailTracker\Events\EmailUnsubscribeEvent;

// In EventServiceProvider
protected $listen = [
    EmailUnsubscribeEvent::class => [
        \App\Listeners\HandleUnsubscribe::class,
    ],
];
```

```php
namespace App\Listeners;

use R0bdiabl0\EmailTracker\Events\EmailUnsubscribeEvent;

class HandleUnsubscribe
{
    public function handle(EmailUnsubscribeEvent $event): void
    {
        $email = $event->email;
        $messageId = $event->messageId;
        $sentEmail = $event->sentEmail; // May be null

        // Update user preferences
        User::where('email', $email)
            ->update(['marketing_emails' => false]);

        // Or remove from specific mailing list based on batch
        if ($sentEmail && $sentEmail->batch) {
            MailingListSubscription::where('email', $email)
                ->where('list', $sentEmail->batch->name)
                ->delete();
        }

        Log::info("User unsubscribed", ['email' => $email]);
    }
}
```

### CSRF Protection

The unsubscribe endpoint needs to be excluded from CSRF protection (it receives POST requests from external email clients):

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'email-tracker/webhook/*',  // Adjust if using custom EMAIL_TRACKER_ROUTE_PREFIX
        'email-tracker/unsubscribe',
    ]);
})
```

> **Note:** If you configured a custom route prefix via `EMAIL_TRACKER_ROUTE_PREFIX`, update the CSRF exclusion paths accordingly.

### Security Recommendations

- **Rate Limiting:** Consider adding rate limiting middleware to your `HandleUnsubscribe` listener or at the route level to prevent abuse:
  ```php
  // In your event listener
  if (RateLimiter::tooManyAttempts('unsubscribe:' . $event->email, 5)) {
      Log::warning('Unsubscribe rate limit exceeded', ['email' => $event->email]);
      return;
  }
  RateLimiter::hit('unsubscribe:' . $event->email, 3600);
  ```
- **Signature Expiration:** For added security, set `signature_expiration` to expire unsubscribe links after a reasonable time (e.g., 720 hours / 30 days)

### What This Feature Does NOT Do

- Does NOT manage subscription lists - you define what "unsubscribe" means for your app
- Does NOT store unsubscribe preferences - you update your own user/subscription models
- Does NOT decide per-list vs global unsubscribe - your listener implements this logic

## Events

The package dispatches events for all tracking activities. Listen to these in your `EventServiceProvider`:

```php
use R0bdiabl0\EmailTracker\Events\EmailSentEvent;
use R0bdiabl0\EmailTracker\Events\EmailDeliveryEvent;
use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;
use R0bdiabl0\EmailTracker\Events\EmailComplaintEvent;
use R0bdiabl0\EmailTracker\Events\EmailOpenEvent;
use R0bdiabl0\EmailTracker\Events\EmailLinkClickEvent;
use R0bdiabl0\EmailTracker\Events\EmailUnsubscribeEvent;

protected $listen = [
    EmailBounceEvent::class => [
        \App\Listeners\HandleEmailBounce::class,
    ],
    EmailComplaintEvent::class => [
        \App\Listeners\HandleEmailComplaint::class,
    ],
    EmailUnsubscribeEvent::class => [
        \App\Listeners\HandleUnsubscribe::class,
    ],
];
```

### Example Listener

```php
namespace App\Listeners;

use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;

class HandleEmailBounce
{
    public function handle(EmailBounceEvent $event): void
    {
        $bounce = $event->emailBounce;
        $email = $bounce->email;
        $type = $bounce->type; // 'Permanent' or 'Transient'

        if ($type === 'Permanent') {
            // Mark user as having invalid email
            User::where('email', $email)->update(['email_valid' => false]);
        }

        // Log for monitoring
        Log::warning("Email bounced", [
            'email' => $email,
            'type' => $type,
            'provider' => $bounce->provider,
        ]);
    }
}
```

## Suppression (Bounce Management)

Automatically skip sending to bounced or complained addresses. This is disabled by default - enable it to protect your sender reputation:

```php
// config/email-tracker.php
'suppression' => [
    'skip_bounced' => true,    // Skip permanently bounced addresses
    'skip_complained' => true, // Skip addresses that filed complaints
],
```

When enabled, suppression works automatically across all sending methods:
- `EmailTracker::send()` facade
- `TracksWithEmail` trait on Mailables
- `EmailTrackerChannel` for Notifications

If a suppressed address is detected, an `AddressSuppressedException` is thrown with the email and reason.

### Manual Suppression Checking

You can also check suppression manually:

```php
use R0bdiabl0\EmailTracker\Services\EmailValidator;

// Check if email should be blocked
if (EmailValidator::shouldBlock('user@example.com')) {
    return; // Don't send
}

// Get specific counts
$bounceCount = EmailValidator::getBounceCount('user@example.com');
$hasComplaint = EmailValidator::hasComplaint('user@example.com');

// Filter a list of emails
$validEmails = EmailValidator::filterBlockedEmails($emailList);
```

## Database Schema

The package creates the following tables (with optional prefix):

### sent_emails

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| provider | string | Email provider (ses, resend, etc.) |
| message_id | string | Provider's message ID |
| email | string | Recipient email address |
| batch_id | bigint | Optional batch reference |
| sent_at | timestamp | When email was sent |
| delivered_at | timestamp | When delivery was confirmed |
| bounce_tracking | boolean | Whether bounce tracking is enabled |
| complaint_tracking | boolean | Whether complaint tracking is enabled |
| delivery_tracking | boolean | Whether delivery tracking is enabled |

### email_bounces

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| provider | string | Email provider |
| sent_email_id | bigint | Reference to sent email |
| type | string | Bounce type (Permanent/Transient) |
| email | string | Bounced email address |
| bounced_at | timestamp | When bounce occurred |

### email_complaints

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| provider | string | Email provider |
| sent_email_id | bigint | Reference to sent email |
| type | string | Complaint type (spam, etc.) |
| email | string | Complaining email address |
| complained_at | timestamp | When complaint occurred |

### email_opens

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| sent_email_id | bigint | Reference to sent email |
| beacon_identifier | string | Unique identifier for tracking pixel |
| opened_at | timestamp | When email was opened |

### email_links

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| sent_email_id | bigint | Reference to sent email |
| link_identifier | string | Unique identifier for link tracking |
| original_url | text | Original link URL |
| clicked | boolean | Whether link has been clicked |
| click_count | integer | Number of clicks |

### batches

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | string | Batch identifier |

## Querying Data

```php
use R0bdiabl0\EmailTracker\Models\SentEmail;
use R0bdiabl0\EmailTracker\Models\Batch;

// Get all bounced emails
$bounced = SentEmail::bounced()->get();

// Get all emails that received complaints
$complained = SentEmail::complained()->get();

// Get emails by provider
$sesEmails = SentEmail::forProvider('ses')->get();

// Get delivered emails
$delivered = SentEmail::delivered()->get();

// Get emails for a specific address
$userEmails = SentEmail::forEmail('user@example.com')->get();

// Get batch with all emails
$batch = Batch::where('name', 'campaign-2024')->with('sentEmails')->first();

// Check if specific email bounced
$email = SentEmail::where('message_id', $messageId)->first();
if ($email->wasBounced()) {
    // Handle bounce
}
```

## Migrating from juhasev/laravel-ses

If you're migrating from `juhasev/laravel-ses`:

```bash
# Preview what will change
php artisan email-tracker:migrate-from-ses --dry-run

# Run migration with table backup
php artisan email-tracker:migrate-from-ses --backup

# Also update namespaces in your code
php artisan email-tracker:migrate-from-ses --backup --update-code
```

The migration will:
- Rename tables (remove `laravel_ses_` prefix)
- Add `provider` column with default `'ses'`
- Output new webhook URLs for AWS SNS configuration

### Backwards Compatibility

The `SesMail` facade is aliased to `EmailTracker`:

```php
// Still works!
SesMail::enableAllTracking()->to($email)->send($mailable);
```

Enable legacy routes to keep old webhook URLs working:

```env
EMAIL_TRACKER_LEGACY_ROUTES=true
```

## Admin Panel Plugins

### Filament Plugin

For **Filament v3/v4** users, install the companion plugin for dashboard widgets, statistics, and resource pages:

```bash
composer require r0bdiabl0/laravel-email-tracker-filament
```

Features:
- **Dashboard Widgets** - Stats overview, delivery charts, health scores, recent activity
- **Resource Pages** - Browse, search, and filter sent emails, bounces, and complaints
- **Statistics Service** - Query aggregated stats for custom integrations

Register in your Filament panel provider:

```php
use R0bdiabl0\EmailTrackerFilament\EmailTrackerFilamentPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            EmailTrackerFilamentPlugin::make(),
        ]);
}
```

See [r0bdiabl0/laravel-email-tracker-filament](https://github.com/r0bdiabl0/laravel-email-tracker-filament) for full documentation.

### Nova Plugin

For **Laravel Nova v4/v5** users, install the companion plugin for resource management:

```bash
composer require r0bdiabl0/laravel-email-tracker-nova
```

Features:
- **Sent Emails Resource** - Browse, search, filter by provider and status
- **Bounces Resource** - View bounce records with type badges
- **Complaints Resource** - Track spam complaints
- **Read-Only** - Safe viewing without accidental modifications

The resources are auto-registered. See [r0bdiabl0/laravel-email-tracker-nova](https://github.com/r0bdiabl0/laravel-email-tracker-nova) for customization options.

## Extending

### Custom Providers

This package is fully extensible. You can add support for any email provider by implementing your own webhook handler.

**Step 1: Create your provider class**

Extend `AbstractProvider` which implements `EmailProviderInterface`:

```php
namespace App\EmailProviders;

use Carbon\Carbon;
use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use R0bdiabl0\EmailTracker\Providers\AbstractProvider;
use Symfony\Component\HttpFoundation\Response;

class CustomSmtpProvider extends AbstractProvider
{
    /**
     * Unique provider name (used in routes and database).
     */
    public function getName(): string
    {
        return 'custom-smtp';
    }

    /**
     * Handle incoming webhook from your email provider.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        $this->logRawPayload($request);

        // Validate webhook signature
        if (! $this->validateSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $eventType = $payload['event_type'] ?? 'unknown';

        // Parse into standardized format
        $data = $this->parsePayload($payload);

        // Route to appropriate handler based on event type
        // The base class helpers expect EmailEventData objects
        return match ($eventType) {
            'bounce' => $this->processBounceEvent($data),
            'complaint' => $this->processComplaintEvent($data),
            'delivered' => $this->processDeliveryEvent($data),
            default => response()->json(['success' => true]),
        };
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        return new EmailEventData(
            messageId: $payload['message_id'] ?? '',
            email: $payload['recipient'] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($payload['event_type'] ?? ''),
            timestamp: isset($payload['timestamp'])
                ? Carbon::parse($payload['timestamp'])
                : null,
            bounceType: $payload['bounce_type'] ?? null,
            metadata: $payload,
        );
    }

    /**
     * Validate webhook signature/authenticity.
     */
    public function validateSignature(Request $request): bool
    {
        $secret = $this->getConfig('webhook_secret');

        if (! $secret) {
            return true; // Skip validation if no secret configured
        }

        $signature = $request->header('X-Custom-Signature');
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature ?? '');
    }

    /**
     * Map provider event types to EmailEventType enum.
     */
    protected function mapEventType(string $event): EmailEventType
    {
        return match ($event) {
            'bounce' => EmailEventType::Bounced,
            'complaint' => EmailEventType::Complained,
            'delivered' => EmailEventType::Delivered,
            'opened' => EmailEventType::Opened,
            'clicked' => EmailEventType::Clicked,
            default => EmailEventType::Sent,
        };
    }
}
```

**Step 2: Register your provider**

In your `AppServiceProvider` or a dedicated service provider:

```php
use R0bdiabl0\EmailTracker\Facades\EmailTracker;
use App\EmailProviders\CustomSmtpProvider;

public function boot(): void
{
    EmailTracker::registerProvider('custom-smtp', CustomSmtpProvider::class);
}
```

**Step 3: Add configuration** (optional)

```php
// config/email-tracker.php
'providers' => [
    // ... built-in providers ...

    'custom-smtp' => [
        'enabled' => env('EMAIL_TRACKER_CUSTOM_SMTP_ENABLED', true),
        'webhook_secret' => env('EMAIL_TRACKER_CUSTOM_SMTP_SECRET'),
    ],
],
```

**Step 4: Configure webhooks in your email provider**

Your custom provider's webhook endpoint is automatically registered at:
```
POST https://your-app.com/email-tracker/webhook/custom-smtp
```

Configure this URL in your email provider's dashboard/settings:

1. **Set the webhook URL** to `https://your-app.com/email-tracker/webhook/custom-smtp`
2. **Select event types** to receive (bounces, complaints, deliveries, opens, clicks)
3. **Configure authentication** - if your provider supports webhook signing:
   - Copy the signing secret/key from your provider
   - Add it to your `.env`: `EMAIL_TRACKER_CUSTOM_SMTP_SECRET=your-secret-here`
4. **Test the webhook** - most providers have a "send test" feature

The package handles routing automatically - any POST request to `/email-tracker/webhook/{provider-name}` will be routed to your provider's `handleWebhook()` method.

### AbstractProvider Helper Methods

The `AbstractProvider` base class provides useful helper methods:

```php
// Logging (respects EMAIL_TRACKER_DEBUG setting)
$this->logDebug('Processing event');
$this->logError('Failed to process');
$this->logInfo('Event received');
$this->logRawPayload($request); // Log full webhook payload

// Configuration access
$secret = $this->getConfig('webhook_secret');

// Event processing helpers - pass an EmailEventData object
// These create database records and fire events automatically
$data = $this->parsePayload($payload);  // You implement this
$this->processBounceEvent($data);       // Creates EmailBounce record
$this->processComplaintEvent($data);    // Creates EmailComplaint record
$this->processDeliveryEvent($data);     // Updates SentEmail.delivered_at
```

**Example using the helper methods in your handleWebhook():**

```php
public function handleWebhook(Request $request, ?string $event = null): Response
{
    $this->logRawPayload($request);

    if (! $this->validateSignature($request)) {
        return response()->json(['error' => 'Invalid signature'], 403);
    }

    $payload = $request->all();
    $eventType = $payload['event_type'] ?? 'unknown';

    // Parse into standardized format
    $data = $this->parsePayload($payload);

    // Use base class event processors
    return match ($eventType) {
        'bounce' => $this->processBounceEvent($data),
        'complaint' => $this->processComplaintEvent($data),
        'delivered' => $this->processDeliveryEvent($data),
        default => response()->json(['success' => true]),
    };
}
```

### Custom Models

Override default models:

```php
// config/email-tracker.php
'models' => [
    'sent_email' => \App\Models\TrackedEmail::class,
    'email_bounce' => \App\Models\CustomBounce::class,
    // ...
],
```

Your custom model should extend the package model or implement the contract:

```php
namespace App\Models;

use R0bdiabl0\EmailTracker\Models\SentEmail as BaseSentEmail;

class TrackedEmail extends BaseSentEmail
{
    // Add custom methods or relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }
}
```

## Testing

```bash
# Run tests
composer test

# Run with coverage
composer test-coverage

# Static analysis
composer analyse

# Code formatting
composer format
```

## Troubleshooting

### Webhooks not receiving data

1. Verify the webhook URL is accessible from the internet
2. Check your web server logs for incoming requests
3. Enable debug logging: `EMAIL_TRACKER_DEBUG=true`
4. Verify signature validation secrets are correct
5. Check Laravel logs for validation errors

### Open tracking not working

1. Open tracking requires HTML emails (not plain text)
2. Many email clients block tracking pixels by default
3. Gmail, Apple Mail, and others may proxy images
4. Consider open tracking as approximate data only

### Message IDs not matching

1. Ensure you're storing the message ID from the send response
2. Different providers format message IDs differently
3. Check that the same message ID format is used in webhooks

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create a feature branch
3. Make your changes with tests
4. Run `composer test` and `composer analyse`
5. Submit a pull request

For bugs and feature requests, please [open an issue](https://github.com/r0bdiabl0/laravel-email-tracker/issues).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Credits

- [Robert Pettique](https://github.com/r0bdiabl0) - Author and maintainer
- Based on the excellent work from [juhasev/laravel-ses](https://github.com/juhasev/laravel-ses)
