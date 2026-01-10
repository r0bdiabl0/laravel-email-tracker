<?php

declare(strict_types=1);

use R0bdiabl0\EmailTracker\Models\Batch;
use R0bdiabl0\EmailTracker\Models\EmailBounce;
use R0bdiabl0\EmailTracker\Models\EmailComplaint;
use R0bdiabl0\EmailTracker\Models\EmailLink;
use R0bdiabl0\EmailTracker\Models\EmailOpen;
use R0bdiabl0\EmailTracker\Models\SentEmail;
use R0bdiabl0\EmailTracker\Providers\MailgunProvider;
use R0bdiabl0\EmailTracker\Providers\PostalProvider;
use R0bdiabl0\EmailTracker\Providers\PostmarkProvider;
use R0bdiabl0\EmailTracker\Providers\ResendProvider;
use R0bdiabl0\EmailTracker\Providers\SendgridProvider;
use R0bdiabl0\EmailTracker\Providers\SesProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    |
    | Set a prefix for all email tracker database tables. Leave empty for no
    | prefix. This allows you to customize table names to avoid conflicts
    | with existing tables or to match your naming conventions.
    |
    | Examples:
    | - '' (empty) -> sent_emails, email_bounces, etc.
    | - 'tracker_' -> tracker_sent_emails, tracker_email_bounces, etc.
    | - 'email_' -> email_sent_emails, email_email_bounces, etc.
    |
    */

    'table_prefix' => env('EMAIL_TRACKER_TABLE_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default email provider to use when not explicitly specified.
    | This should match one of the enabled providers below.
    |
    */

    'default_provider' => env('EMAIL_TRACKER_DEFAULT_PROVIDER', 'ses'),

    /*
    |--------------------------------------------------------------------------
    | Email Providers
    |--------------------------------------------------------------------------
    |
    | Configure your email service providers. Each provider can have its own
    | settings and webhook handler. Enable only the providers you use.
    |
    */

    'providers' => [

        'ses' => [
            'enabled' => env('EMAIL_TRACKER_SES_ENABLED', true),
            'handler' => SesProvider::class,

            // AWS SNS signature validation (recommended for production)
            'sns_validator' => env('EMAIL_TRACKER_SNS_VALIDATOR', true),

            // SMTP thresholds for SES
            'ping_threshold' => env('EMAIL_TRACKER_SES_PING_THRESHOLD', 10),
            'restart_threshold' => env('EMAIL_TRACKER_SES_RESTART_THRESHOLD', 100),
            'restart_sleep' => env('EMAIL_TRACKER_SES_RESTART_SLEEP', 0),
        ],

        'resend' => [
            'enabled' => env('EMAIL_TRACKER_RESEND_ENABLED', false),
            'handler' => ResendProvider::class,

            // Resend webhook signing secret
            'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        ],

        'postal' => [
            'enabled' => env('EMAIL_TRACKER_POSTAL_ENABLED', false),
            'handler' => PostalProvider::class,

            // Postal webhook key
            'webhook_key' => env('POSTAL_WEBHOOK_KEY'),
        ],

        'mailgun' => [
            'enabled' => env('EMAIL_TRACKER_MAILGUN_ENABLED', false),
            'handler' => MailgunProvider::class,

            // Mailgun webhook signing key
            'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
        ],

        'sendgrid' => [
            'enabled' => env('EMAIL_TRACKER_SENDGRID_ENABLED', false),
            'handler' => SendgridProvider::class,

            // SendGrid Event Webhook verification key
            'verification_key' => env('SENDGRID_VERIFICATION_KEY'),
        ],

        'postmark' => [
            'enabled' => env('EMAIL_TRACKER_POSTMARK_ENABLED', false),
            'handler' => PostmarkProvider::class,

            // Postmark webhook token
            'webhook_token' => env('POSTMARK_WEBHOOK_TOKEN'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking Options
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific tracking features globally.
    |
    */

    'tracking' => [
        'opens' => env('EMAIL_TRACKER_TRACK_OPENS', true),
        'links' => env('EMAIL_TRACKER_TRACK_LINKS', true),
        'bounces' => env('EMAIL_TRACKER_TRACK_BOUNCES', true),
        'complaints' => env('EMAIL_TRACKER_TRACK_COMPLAINTS', true),
        'deliveries' => env('EMAIL_TRACKER_TRACK_DELIVERIES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Options
    |--------------------------------------------------------------------------
    |
    | Configure automatic validation to skip sending to problematic addresses.
    |
    */

    'validation' => [
        // Automatically skip emails that have bounced
        'skip_bounced' => env('EMAIL_TRACKER_SKIP_BOUNCED', false),

        // Automatically skip emails that have complained (marked as spam)
        'skip_complained' => env('EMAIL_TRACKER_SKIP_COMPLAINED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the routes for webhook endpoints and tracking pixels/links.
    |
    */

    'routes' => [
        // Enable or disable route registration
        'enabled' => env('EMAIL_TRACKER_ROUTES_ENABLED', true),

        // Route prefix for all email tracker routes
        'prefix' => env('EMAIL_TRACKER_ROUTE_PREFIX', 'email-tracker'),

        // Middleware to apply to webhook routes (usually empty for webhooks)
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Routes (Backwards Compatibility)
    |--------------------------------------------------------------------------
    |
    | Enable legacy route paths for backwards compatibility with
    | juhasev/laravel-ses. This registers additional routes using the old
    | URL patterns alongside the new ones.
    |
    */

    'legacy_routes' => [
        'enabled' => env('EMAIL_TRACKER_LEGACY_ROUTES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Channel
    |--------------------------------------------------------------------------
    |
    | Configure the optional notification channel for tracked email sending.
    |
    */

    'notifications' => [
        // Enable the built-in notification channel
        'channel_enabled' => env('EMAIL_TRACKER_NOTIFICATION_CHANNEL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable debug mode for detailed logging. Useful during development
    | and troubleshooting. Disable in production.
    |
    */

    'debug' => env('EMAIL_TRACKER_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Log Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all log messages from this package.
    |
    */

    'log_prefix' => env('EMAIL_TRACKER_LOG_PREFIX', 'EMAIL-TRACKER'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the package. You can extend the
    | default models and specify your custom classes here.
    |
    */

    'models' => [
        'batch' => Batch::class,
        'sent_email' => SentEmail::class,
        'email_bounce' => EmailBounce::class,
        'email_complaint' => EmailComplaint::class,
        'email_link' => EmailLink::class,
        'email_open' => EmailOpen::class,
    ],

];
