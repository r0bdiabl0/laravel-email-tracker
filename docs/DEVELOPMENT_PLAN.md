# Plan: Create r0bdiabl0/laravel-email-tracker Package

> **Note**: This document will be saved to `~/src/laravel-email-tracker/docs/DEVELOPMENT_PLAN.md` when the repo is created.

## Overview
Fork `juhasev/laravel-ses` and transform it into `r0bdiabl0/laravel-email-tracker` - a **multi-provider** email tracking package for Laravel 12+ with configurable table names and unified bounce/complaint tracking across SES, Resend, Postal, and other providers.

## Package Details
- **Name**: `r0bdiabl0/laravel-email-tracker`
- **Namespace**: `R0bdiabl0\EmailTracker`
- **Location**: `~/src/laravel-email-tracker`
- **GitHub**: Personal account (r0bdiabl0)
- **Initial Version**: v1.0.0 (fresh start, not continuing juhasev versioning)
- **PHP**: 8.2+ (8.3+ recommended for Laravel 13 readiness)
- **Laravel**: 11.0+, 12.0+, 13.0+ (when released)

## Important Guidelines
- **Public Package**: This is a public open-source package
- **No AI References**: Do not include any AI/assistant mentions in code, comments, or git commits
- **Standard Attribution**: Use standard open-source commit messages and code comments

---

## Key Design Decisions

### 1. Configurable Table Prefix (or No Prefix)
```php
// config/email-tracker.php
'table_prefix' => env('EMAIL_TRACKER_TABLE_PREFIX', ''), // Default: no prefix

// Results in tables like:
// - sent_emails (not laravel_ses_sent_emails)
// - email_bounces
// - email_complaints
// - email_opens
// - email_links
// - batches

// Or with prefix 'tracker_':
// - tracker_sent_emails
// - tracker_email_bounces
// etc.
```

### 2. Provider Column for Multi-Provider Support
```php
// sent_emails table gains 'provider' column
Schema::table('sent_emails', function (Blueprint $table) {
    $table->string('provider')->default('ses'); // ses, resend, postal, mailgun, etc.
});

// Same for bounces, complaints - track which provider reported them
```

### 3. Provider-Agnostic Facade
```php
// Instead of SesMail::, use EmailTracker:: or keep SesMail:: as alias for backwards compat
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

EmailTracker::provider('ses')
    ->enableAllTracking()
    ->to($email)
    ->send($mailable);

// Or auto-detect from Laravel's mail config
EmailTracker::enableAllTracking()
    ->to($email)
    ->send($mailable); // Uses default mail provider
```

---

## Laravel Best Practices & Modern Features

### Service Provider Best Practices (Laravel 11+)

```php
// src/EmailTrackerServiceProvider.php
namespace R0bdiabl0\EmailTracker;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class EmailTrackerServiceProvider extends ServiceProvider
{
    /**
     * Register services - for bindings and singletons only.
     * Do NOT load config, routes, views, or migrations here.
     */
    public function register(): void
    {
        // Merge config early so it's available during registration
        $this->mergeConfigFrom(__DIR__ . '/../config/email-tracker.php', 'email-tracker');

        // Register core services as singletons
        $this->app->singleton(TrackedMailer::class, function (Application $app) {
            return new TrackedMailer(
                config('email-tracker.default_provider'),
                $app->make('mail.manager')
            );
        });

        // Register facade accessor
        $this->app->alias(TrackedMailer::class, 'email-tracker');

        // Register provider handlers
        $this->registerProviders();
    }

    /**
     * Bootstrap services - for loading resources.
     * Check runningInConsole() before registering commands.
     */
    public function boot(): void
    {
        // Publish config with tag for selective publishing
        $this->publishes([
            __DIR__ . '/../config/email-tracker.php' => config_path('email-tracker.php'),
        ], 'email-tracker-config');

        // Use publishesMigrations() for automatic timestamp updates (Laravel 11+)
        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'email-tracker-migrations');

        // Load routes only if enabled in config
        if (config('email-tracker.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        // Register commands only when running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\MigrateFromSes::class,
                Commands\RollbackMigration::class,
                Commands\InstallCommand::class,
            ]);
        }

        // Register event listeners
        $this->registerEventListeners();
    }

    /**
     * Register provider handlers from config.
     */
    protected function registerProviders(): void
    {
        foreach (config('email-tracker.providers', []) as $name => $settings) {
            if ($settings['enabled'] ?? false) {
                $this->app->singleton("email-tracker.provider.{$name}", function () use ($settings) {
                    return new $settings['handler']();
                });
            }
        }
    }
}
```

### Modern PHP 8.2+ Features

```php
// Use constructor property promotion
class SentEmail extends Model
{
    public function __construct(
        protected string $provider = 'ses',
        protected ?string $messageId = null,
    ) {
        parent::__construct();
    }
}

// Use readonly properties where appropriate
final readonly class EmailEventData
{
    public function __construct(
        public string $messageId,
        public string $email,
        public string $provider,
        public string $eventType,
        public ?Carbon $timestamp = null,
        public array $metadata = [],
    ) {}
}

// Use enums for type safety
enum EmailEventType: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Opened = 'opened';
    case Clicked = 'clicked';
}

enum BounceType: string
{
    case Permanent = 'Permanent';
    case Transient = 'Transient';
    case Undetermined = 'Undetermined';
}

// Use match expressions
public function getProviderHandler(string $provider): EmailProviderInterface
{
    return match ($provider) {
        'ses' => $this->app->make(SesProvider::class),
        'resend' => $this->app->make(ResendProvider::class),
        'postal' => $this->app->make(PostalProvider::class),
        'mailgun' => $this->app->make(MailgunProvider::class),
        'sendgrid' => $this->app->make(SendgridProvider::class),
        'postmark' => $this->app->make(PostmarkProvider::class),
        default => throw new InvalidProviderException("Unknown provider: {$provider}"),
    };
}
```

### Laravel 13 Readiness

```php
// Support for typed Eloquent properties (Laravel 13)
// Use PHP 8.3+ features when available

class SentEmail extends Model
{
    // Laravel 13 will support typed properties
    // Prepare models to be compatible
    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'provider' => 'string',
    ];

    // Use new Eloquent features
    protected static function booted(): void
    {
        static::creating(function (SentEmail $email) {
            $email->provider ??= config('email-tracker.default_provider', 'ses');
        });
    }
}

// Use modern attribute syntax
#[Attribute]
class TracksEmail
{
    public function __construct(
        public ?string $batch = null,
        public ?string $provider = null,
    ) {}
}
```

### Updated composer.json with Laravel 13 Support

```json
{
  "name": "r0bdiabl0/laravel-email-tracker",
  "description": "Multi-provider email tracking for Laravel - track opens, clicks, bounces, complaints across SES, Resend, Postal, and more",
  "keywords": ["Laravel", "Email", "Tracking", "SES", "Resend", "Postal", "Bounces", "Analytics"],
  "license": "MIT",
  "require": {
    "php": "^8.2",
    "illuminate/contracts": "^11.0|^12.0|^13.0",
    "illuminate/mail": "^11.0|^12.0|^13.0",
    "illuminate/support": "^11.0|^12.0|^13.0",
    "illuminate/notifications": "^11.0|^12.0|^13.0",
    "illuminate/database": "^11.0|^12.0|^13.0",
    "aws/aws-sdk-php": "^3.288",
    "guzzlehttp/guzzle": "^7.8",
    "aws/aws-php-sns-message-validator": "^1.7",
    "nesbot/carbon": "^3.0"
  },
  "require-dev": {
    "orchestra/testbench": "^9.0|^10.0|^11.0",
    "phpunit/phpunit": "^11.0",
    "mockery/mockery": "^1.6",
    "phpstan/phpstan": "^1.10",
    "laravel/pint": "^1.13"
  },
  "autoload": {
    "psr-4": {
      "R0bdiabl0\\EmailTracker\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "R0bdiabl0\\EmailTracker\\Tests\\": "tests/"
    }
  },
  "extra": {
    "laravel": {
      "providers": [
        "R0bdiabl0\\EmailTracker\\EmailTrackerServiceProvider"
      ],
      "aliases": {
        "EmailTracker": "R0bdiabl0\\EmailTracker\\Facades\\EmailTracker",
        "SesMail": "R0bdiabl0\\EmailTracker\\Facades\\EmailTracker"
      }
    }
  },
  "config": {
    "sort-packages": true
  },
  "minimum-stability": "stable",
  "prefer-stable": true,
  "scripts": {
    "test": "phpunit",
    "test-coverage": "phpunit --coverage-html coverage",
    "analyse": "phpstan analyse",
    "format": "pint"
  }
}
```

### Migration Best Practices

```php
// database/migrations/create_email_tracker_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Get table name with configured prefix.
     */
    protected function tableName(string $name): string
    {
        $prefix = config('email-tracker.table_prefix', '');
        return $prefix ? "{$prefix}_{$name}" : $name;
    }

    public function up(): void
    {
        // Use UUID v7 for primary keys (Laravel 12+ feature)
        Schema::create($this->tableName('sent_emails'), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('ses')->index();
            $table->string('message_id')->index();
            $table->string('email')->index();
            $table->foreignId('batch_id')->nullable()->constrained($this->tableName('batches'))->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Composite index for common queries
            $table->index(['provider', 'email']);
            $table->index(['provider', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName('sent_emails'));
    }
};
```

### Install Command (Laravel Best Practice)

```php
// src/Commands/InstallCommand.php
class InstallCommand extends Command
{
    protected $signature = 'email-tracker:install';
    protected $description = 'Install the Email Tracker package';

    public function handle(): int
    {
        $this->info('Installing Email Tracker...');

        // Publish config
        $this->call('vendor:publish', [
            '--tag' => 'email-tracker-config',
        ]);

        // Publish and run migrations
        $this->call('vendor:publish', [
            '--tag' => 'email-tracker-migrations',
        ]);

        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
        }

        $this->info('Email Tracker installed successfully!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Configure your providers in config/email-tracker.php');
        $this->line('  2. Set up webhook URLs in your email provider dashboard');
        $this->line('  3. Start tracking emails with EmailTracker::enableAllTracking()');

        return Command::SUCCESS;
    }
}
```

---

## Phase 1: Fork and Transform

### 1.1 Create New Repository
```bash
# Create fresh repo (not a GitHub fork to avoid upstream confusion)
mkdir ~/src/laravel-email-tracker
cd ~/src/laravel-email-tracker
git init

# Copy source from juhasev/laravel-ses as starting point
```

### 1.2 Namespace Changes
- `Juhasev\LaravelSes` → `R0bdiabl0\EmailTracker`
- All files in `src/`, `tests/`

### 1.3 composer.json
```json
{
  "name": "r0bdiabl0/laravel-email-tracker",
  "description": "Multi-provider email tracking for Laravel - track opens, clicks, bounces, complaints across SES, Resend, Postal, and more",
  "keywords": ["Laravel", "Email", "Tracking", "SES", "Resend", "Postal", "Bounces", "Analytics"],
  "extra": {
    "laravel": {
      "providers": ["R0bdiabl0\\EmailTracker\\EmailTrackerServiceProvider"],
      "aliases": {
        "EmailTracker": "R0bdiabl0\\EmailTracker\\Facades\\EmailTracker",
        "SesMail": "R0bdiabl0\\EmailTracker\\Facades\\EmailTracker"
      }
    }
  }
}
```

---

## Phase 2: Laravel 12 Compatibility

### 2.1 Dependency Updates
```json
{
  "require": {
    "php": "^8.2",
    "illuminate/contracts": "^11.0|^12.0",
    "illuminate/mail": "^11.0|^12.0",
    "illuminate/support": "^11.0|^12.0",
    "illuminate/notifications": "^11.0|^12.0",
    "aws/aws-sdk-php": "^3.288.0",
    "ramsey/uuid": "^4.3",
    "guzzlehttp/guzzle": "^7.8.1",
    "aws/aws-php-sns-message-validator": "^1.7",
    "symfony/psr-http-message-bridge": "^7.0",
    "nyholm/psr7": "^1.0",
    "voku/simple_html_dom": "^4.8",
    "nesbot/carbon": "^3.0"
  },
  "require-dev": {
    "orchestra/testbench": "^9.0|^10.0",
    "phpunit/phpunit": "^11.0",
    "mockery/mockery": "^1.6"
  }
}
```

### 2.2 Laravel 12 Breaking Changes
- Carbon 3.x required (package already compatible)
- PHPUnit 11 for tests
- Orchestra Testbench 10

---

## Phase 3: Multi-Provider Architecture

### 3.1 Configuration File
```php
// config/email-tracker.php
return [
    // Table prefix (empty = no prefix, allows: sent_emails, email_bounces, etc.)
    'table_prefix' => env('EMAIL_TRACKER_TABLE_PREFIX', ''),

    // Default provider (used when not explicitly specified)
    'default_provider' => env('EMAIL_TRACKER_DEFAULT_PROVIDER', 'ses'),

    // Provider-specific settings
    'providers' => [
        'ses' => [
            'enabled' => true,
            'sns_validator' => env('EMAIL_TRACKER_SNS_VALIDATOR', true),
            'webhook_routes' => true, // Auto-register SNS webhook routes
        ],
        'resend' => [
            'enabled' => false,
            'webhook_routes' => true,
        ],
        'postal' => [
            'enabled' => false,
            'webhook_routes' => true,
        ],
        'mailgun' => [
            'enabled' => false,
            'webhook_routes' => true,
        ],
    ],

    // Tracking options
    'tracking' => [
        'opens' => true,
        'links' => true,
        'bounces' => true,
        'complaints' => true,
        'deliveries' => true,
    ],

    // Validation - auto-skip bounced/complained addresses
    'validation' => [
        'skip_bounced' => false,
        'skip_complained' => false,
    ],

    // Debug mode
    'debug' => env('EMAIL_TRACKER_DEBUG', false),

    // Custom models (for extending)
    'models' => [
        'sent_email' => \R0bdiabl0\EmailTracker\Models\SentEmail::class,
        'email_bounce' => \R0bdiabl0\EmailTracker\Models\EmailBounce::class,
        'email_complaint' => \R0bdiabl0\EmailTracker\Models\EmailComplaint::class,
        'email_open' => \R0bdiabl0\EmailTracker\Models\EmailOpen::class,
        'email_link' => \R0bdiabl0\EmailTracker\Models\EmailLink::class,
        'batch' => \R0bdiabl0\EmailTracker\Models\Batch::class,
    ],
];
```

### 3.2 Updated Database Schema
```php
// Migration: create_email_tracker_tables.php
// Uses configurable prefix from config

Schema::create($this->tableName('sent_emails'), function (Blueprint $table) {
    $table->id();
    $table->string('provider')->default('ses'); // NEW: ses, resend, postal, etc.
    $table->string('message_id')->index();
    $table->string('email')->index();
    $table->foreignId('batch_id')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamps();
});

Schema::create($this->tableName('email_bounces'), function (Blueprint $table) {
    $table->id();
    $table->string('provider')->default('ses'); // NEW
    $table->foreignId('sent_email_id');
    $table->string('type')->nullable(); // Permanent, Transient
    $table->string('email')->index();
    $table->timestamp('bounced_at');
    $table->timestamps();
});

// Similar for email_complaints, email_opens, email_links, batches
```

### 3.3 Simplified Controller Structure
```
src/
├── Controllers/
│   ├── WebhookController.php       # Single dynamic controller for all providers
│   ├── OpenController.php          # Beacon tracking (provider-agnostic)
│   └── LinkController.php          # Link tracking (provider-agnostic)
├── Providers/                      # Provider-specific handlers (not controllers)
│   ├── Contracts/
│   │   └── EmailProviderInterface.php
│   ├── SesProvider.php
│   ├── ResendProvider.php
│   ├── PostalProvider.php
│   ├── MailgunProvider.php
│   ├── SendgridProvider.php
│   └── PostmarkProvider.php
```

### 3.4 Dynamic Webhook Routes
Single dynamic endpoint pattern - the controller identifies the provider from the route.

```php
// config/email-tracker.php
'routes' => [
    'enabled' => true,
    'prefix' => env('EMAIL_TRACKER_ROUTE_PREFIX', 'email-tracker'),
    'middleware' => [],

    // Tracking endpoints
    'beacon' => '/beacon/{beaconId}',
    'link' => '/link/{linkId}',
],

// routes.php - Simple dynamic pattern
Route::prefix(config('email-tracker.routes.prefix'))->group(function () {
    // Dynamic webhook - provider identified from route
    Route::post('/webhook/{provider}', [WebhookController::class, 'handle']);
    Route::post('/webhook/{provider}/{event}', [WebhookController::class, 'handle']);

    // Tracking (provider-agnostic)
    Route::get('/beacon/{beaconId}', [OpenController::class, 'track']);
    Route::get('/link/{linkId}', [LinkController::class, 'track']);
});

// Results in endpoints like:
// POST /email-tracker/webhook/ses
// POST /email-tracker/webhook/ses/bounce (for SES which has separate event types)
// POST /email-tracker/webhook/resend
// POST /email-tracker/webhook/postal
// POST /email-tracker/webhook/mailgun
// GET  /email-tracker/beacon/{beaconId}
// GET  /email-tracker/link/{linkId}
```

### 3.5 Dynamic Webhook Controller
```php
// src/Controllers/WebhookController.php
class WebhookController
{
    public function handle(Request $request, string $provider, ?string $event = null)
    {
        // Get the registered handler for this provider
        $handler = EmailTracker::getProviderHandler($provider);

        if (!$handler) {
            abort(404, "Unknown provider: {$provider}");
        }

        // Let the handler process the webhook
        return $handler->handleWebhook($request, $event);
    }
}
```

### 3.6 Extensible Provider System
```php
// Provider interface - implement this for custom providers
interface EmailProviderInterface
{
    public function handleWebhook(Request $request, ?string $event = null): Response;
    public function parsePayload(array $payload): EmailEventData;
    public function validateSignature(Request $request): bool;
}

// Register custom providers at runtime
EmailTracker::registerProvider('custom-smtp', CustomSmtpProvider::class);

// Built-in providers
class SesProvider implements EmailProviderInterface { ... }
class ResendProvider implements EmailProviderInterface { ... }
class PostalProvider implements EmailProviderInterface { ... }
class MailgunProvider implements EmailProviderInterface { ... }
class SendgridProvider implements EmailProviderInterface { ... }
class PostmarkProvider implements EmailProviderInterface { ... }

// Config determines which are enabled
'providers' => [
    'ses' => ['enabled' => true, 'handler' => SesProvider::class],
    'resend' => ['enabled' => true, 'handler' => ResendProvider::class],
    'postal' => ['enabled' => true, 'handler' => PostalProvider::class],
    // Users can add their own:
    // 'custom' => ['enabled' => true, 'handler' => \App\Providers\CustomProvider::class],
],
```

---

## Phase 4: Core Improvements

### 4.1 Optional, Extendable Notification Channel
The notification channel is **optional** - users can use the core tracking features without it.
When needed, it's designed to be extended for custom use cases.

```php
// config/email-tracker.php
'notifications' => [
    'channel_enabled' => false, // Opt-in, not forced
    'channel_class' => \R0bdiabl0\EmailTracker\Notifications\EmailTrackerChannel::class,
],

// Base channel - simple, extendable
// src/Notifications/EmailTrackerChannel.php
class EmailTrackerChannel
{
    public function send($notifiable, Notification $notification): void
    {
        // Core tracking logic - can be overridden
    }

    // Protected methods for extension points
    protected function buildMessage($notifiable, $notification): mixed { ... }
    protected function getRecipient($notifiable): ?string { ... }
    protected function shouldSend($notifiable, $notification): bool { ... }
    protected function beforeSend($notifiable, $notification): void { ... }
    protected function afterSend($notifiable, $notification, $result): void { ... }
}

// Users extend for their needs (like swingular-platform does)
class CustomEmailTrackerChannel extends EmailTrackerChannel
{
    protected function shouldSend($notifiable, $notification): bool
    {
        // Add bounce checking, user preferences, rate limiting, etc.
        return parent::shouldSend($notifiable, $notification);
    }
}

// Usage is optional:
public function via($notifiable): array
{
    return [\App\Notifications\CustomEmailTrackerChannel::class];
    // Or use built-in if simple tracking is enough:
    // return [\R0bdiabl0\EmailTracker\Notifications\EmailTrackerChannel::class];
}
```

### 4.2 Mailable Tracking Fix
```php
// Fix: SesMail::send($mailable) now properly tracks Message ID
protected function sendMailable(Mailable $mailable): ?SentMessage
{
    return $this->sendMailableWithTracking($mailable);
}
```

### 4.3 Pre-Send Validation
```php
// src/Services/EmailValidator.php
class EmailValidator
{
    public static function shouldBlock(string $email, ?string $provider = null): bool;
    public static function getBounceCount(string $email, ?string $provider = null): int;
    public static function getComplaintCount(string $email, ?string $provider = null): int;
    public static function filterBlockedEmails(array $emails, ?string $provider = null): array;
}

// Fluent API
EmailTracker::enableAllTracking()
    ->skipBouncedAndComplained() // Auto-filter
    ->to($emails)
    ->send($mailable);
```

### 4.4 Optional TracksWithEmail Trait
An **optional** trait for Mailables - provides convenience methods but isn't required.
Users can extend or create their own implementation.

```php
// src/Traits/TracksWithEmail.php
trait TracksWithEmail
{
    // Core methods - simple and focused
    public static function sendTracked(
        string|array $to,
        ?string $batch = null,
        ?string $provider = null
    ): bool;

    public static function queueTracked(
        string|array $to,
        ?string $batch = null,
        string $queue = 'default',
        ?string $provider = null
    ): void;

    // Extension points - override in your Mailable for custom behavior
    protected static function shouldBlockEmail(string $email): bool
    {
        return false; // Override to add bounce/complaint checking
    }

    protected static function filterRecipients(array $emails): array
    {
        return $emails; // Override to filter addresses
    }

    protected static function getDefaultBatch(): ?string
    {
        return null; // Override to set default batch name
    }
}

// Usage is optional - use the trait if you want convenience methods
class WelcomeEmail extends Mailable
{
    use TracksWithEmail;

    // Optionally override for custom filtering
    protected static function shouldBlockEmail(string $email): bool
    {
        return EmailValidator::shouldBlock($email);
    }
}

// Or don't use the trait at all - call EmailTracker directly
EmailTracker::enableAllTracking()
    ->setBatch('welcome')
    ->to($email)
    ->send(new WelcomeEmail());
```

### 4.5 Unified Statistics
```php
// src/Services/Stats.php - Now supports cross-provider queries
Stats::forEmail($email);                    // All providers
Stats::forEmail($email, 'ses');             // SES only
Stats::forBatch($batch);                    // All providers
Stats::forBatch($batch, 'resend');          // Resend only
Stats::forProvider('ses');                  // All SES stats
Stats::crossProvider();                     // Aggregate all providers
```

---

## Phase 5: Backwards Compatibility

### 5.1 Aliases for Migration
```php
// Keep SesMail as alias for easy migration
'aliases' => [
    'EmailTracker' => EmailTracker::class,
    'SesMail' => EmailTracker::class,  // Backwards compat
]
```

### 5.2 Legacy Route Support
```php
// config option to use old route paths
'legacy_routes' => [
    'enabled' => false,
    // When true, also registers:
    // /ses/notification/bounce (in addition to /email-tracker/webhook/ses/bounce)
    // /ses/beacon/{id} (in addition to /email-tracker/beacon/{id})
]
```

### 5.3 Automatic Migration Tool

A comprehensive artisan command to automatically convert from `juhasev/laravel-ses`:

```bash
# Full automatic migration (interactive by default)
php artisan email-tracker:migrate-from-ses

# Non-interactive mode (for CI/CD)
php artisan email-tracker:migrate-from-ses --force

# Preview changes without executing
php artisan email-tracker:migrate-from-ses --dry-run

# Keep old tables as backup (rename to *_backup)
php artisan email-tracker:migrate-from-ses --backup
```

#### What the Migration Tool Does:

**1. Database Migration**
```php
// Renames tables based on configured prefix
// From: laravel_ses_sent_emails -> sent_emails (or {prefix}_sent_emails)
// From: laravel_ses_email_bounces -> email_bounces
// From: laravel_ses_email_complaints -> email_complaints
// From: laravel_ses_email_opens -> email_opens
// From: laravel_ses_email_links -> email_links
// From: laravel_ses_batches -> batches

// Adds provider column with default 'ses'
Schema::table('sent_emails', function (Blueprint $table) {
    $table->string('provider')->default('ses')->after('id');
});
```

**2. Config Migration**
```php
// Reads old config/laravelses.php
// Generates new config/email-tracker.php with mapped values
// Maps: 'aws.key' -> 'providers.ses.key'
// Maps: 'aws.region' -> 'providers.ses.region'
// Preserves custom settings
```

**3. Namespace Updates (optional)**
```bash
# Also update PHP files (with --update-code flag)
php artisan email-tracker:migrate-from-ses --update-code

# This finds and replaces:
# - use Juhasev\LaravelSes\... -> use R0bdiabl0\EmailTracker\...
# - SesMail:: -> EmailTracker:: (optional, aliases work too)
```

**4. Route URL Generator**
```php
// Outputs new webhook URLs for AWS SNS configuration
// Old: POST /laravel-ses/notification/bounce
// New: POST /email-tracker/webhook/ses/bounce

// Generates AWS CLI commands to update SNS subscriptions
echo "aws sns subscribe --topic-arn {$topicArn} --protocol https --notification-endpoint {$newUrl}";
```

#### Migration Command Implementation
```php
// src/Commands/MigrateFromSes.php
class MigrateFromSes extends Command
{
    protected $signature = 'email-tracker:migrate-from-ses
        {--force : Run without confirmation}
        {--dry-run : Preview changes without executing}
        {--backup : Keep old tables as *_backup}
        {--update-code : Also update PHP file namespaces}
        {--skip-config : Skip config file migration}
        {--skip-database : Skip database table migration}';

    protected $description = 'Migrate from juhasev/laravel-ses to r0bdiabl0/laravel-email-tracker';

    public function handle(): int
    {
        $this->info('Laravel Email Tracker - Migration from juhasev/laravel-ses');
        $this->newLine();

        // 1. Check prerequisites
        if (!$this->checkPrerequisites()) {
            return Command::FAILURE;
        }

        // 2. Show migration plan
        $this->showMigrationPlan();

        // 3. Confirm (unless --force or --dry-run)
        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('Proceed with migration?')) {
                return Command::FAILURE;
            }
        }

        // 4. Execute migrations
        if (!$this->option('skip-database')) {
            $this->migrateTables();
        }

        if (!$this->option('skip-config')) {
            $this->migrateConfig();
        }

        if ($this->option('update-code')) {
            $this->updateCodeNamespaces();
        }

        // 5. Show webhook URL updates
        $this->showWebhookUpdates();

        $this->info('Migration complete!');
        return Command::SUCCESS;
    }

    protected function migrateTables(): void
    {
        $prefix = config('email-tracker.table_prefix', '');
        $oldTables = [
            'laravel_ses_sent_emails' => $prefix . 'sent_emails',
            'laravel_ses_email_bounces' => $prefix . 'email_bounces',
            'laravel_ses_email_complaints' => $prefix . 'email_complaints',
            'laravel_ses_email_opens' => $prefix . 'email_opens',
            'laravel_ses_email_links' => $prefix . 'email_links',
            'laravel_ses_batches' => $prefix . 'batches',
        ];

        foreach ($oldTables as $old => $new) {
            if (Schema::hasTable($old)) {
                if ($this->option('backup')) {
                    Schema::rename($old, $old . '_backup');
                    Schema::rename($old . '_backup', $new);
                } else {
                    Schema::rename($old, $new);
                }
                $this->line("  Renamed: {$old} -> {$new}");

                // Add provider column if not exists
                if (!Schema::hasColumn($new, 'provider')) {
                    Schema::table($new, function (Blueprint $table) {
                        $table->string('provider')->default('ses')->after('id');
                    });
                    $this->line("  Added provider column to: {$new}");
                }
            }
        }
    }

    protected function showWebhookUpdates(): void
    {
        $this->newLine();
        $this->warn('Update your AWS SNS subscriptions with these new webhook URLs:');
        $this->newLine();

        $baseUrl = config('app.url');
        $prefix = config('email-tracker.routes.prefix', 'email-tracker');

        $webhooks = [
            'Bounce' => "{$baseUrl}/{$prefix}/webhook/ses/bounce",
            'Complaint' => "{$baseUrl}/{$prefix}/webhook/ses/complaint",
            'Delivery' => "{$baseUrl}/{$prefix}/webhook/ses/delivery",
        ];

        foreach ($webhooks as $type => $url) {
            $this->line("  {$type}: {$url}");
        }
    }
}
```

#### Rollback Command
```bash
# If something goes wrong, rollback the migration
php artisan email-tracker:rollback-migration

# This restores backup tables (if --backup was used)
# and reverts config changes
```

---

## Implementation Steps

### Step 1: Create Repository (~15 min)
1. Create new repo `r0bdiabl0/laravel-email-tracker`
2. Clone to `~/src/laravel-email-tracker`
3. Copy source from juhasev/laravel-ses as starting point

### Step 2: Rebrand & Restructure (~1 hour)
1. Global namespace change: `Juhasev\LaravelSes` → `R0bdiabl0\EmailTracker`
2. Rename key files/classes:
   - `LaravelSesServiceProvider` → `EmailTrackerServiceProvider`
   - `SesMailer` → `TrackedMailer`
   - `SesMail` facade → `EmailTracker` facade
3. Update composer.json with new name and aliases

### Step 3: Database Changes (~1 hour)
1. Update migrations to use configurable prefix
2. Add `provider` column to all tables
3. Create migration helper for table name resolution
4. Test with empty prefix (default)

### Step 4: Multi-Provider Architecture (~2 hours)
1. Create provider-specific webhook controllers
2. Update routes to be provider-aware
3. Implement provider detection/selection
4. Update config file structure

### Step 5: Laravel 12 Updates (~30 min)
1. Update composer.json dependencies
2. Fix any deprecations
3. Update tests for PHPUnit 11

### Step 6: Core Improvements (~3 hours)
1. Fix Mailable tracking (sendMailable override)
2. Add EmailValidator service
3. Create EmailTrackerChannel for notifications
4. Create TracksWithEmail trait
5. Update Stats service for multi-provider

### Step 7: Testing (~1-2 hours)
1. Update existing tests
2. Add tests for new features
3. Test in swingular-platform

### Step 8: Documentation (~1 hour)
1. Comprehensive README
2. CHANGELOG.md
3. UPGRADE.md (migration from juhasev/laravel-ses)

---

## Files to Create/Modify

### New Files
- `src/EmailTrackerServiceProvider.php` - Main service provider (Laravel 11+ best practices)
- `src/Facades/EmailTracker.php` - Main facade
- `src/Controllers/WebhookController.php` - Single dynamic webhook controller
- `src/Providers/Contracts/EmailProviderInterface.php` - Provider interface
- `src/Providers/SesProvider.php` - SES webhook handler
- `src/Providers/ResendProvider.php` - Resend webhook handler
- `src/Providers/PostalProvider.php` - Postal webhook handler
- `src/Providers/MailgunProvider.php` - Mailgun webhook handler
- `src/Providers/SendgridProvider.php` - SendGrid webhook handler
- `src/Providers/PostmarkProvider.php` - Postmark webhook handler
- `src/Services/EmailValidator.php` - Pre-send validation
- `src/Traits/TracksWithEmail.php` - Optional mailable tracking trait
- `src/Notifications/EmailTrackerChannel.php` - Optional notification channel
- `src/Commands/InstallCommand.php` - Package installation wizard
- `src/Commands/MigrateFromSes.php` - Automatic migration from juhasev/laravel-ses
- `src/Commands/RollbackMigration.php` - Rollback migration if needed
- `src/Enums/EmailEventType.php` - PHP 8.1+ enum for event types
- `src/Enums/BounceType.php` - PHP 8.1+ enum for bounce types
- `src/DataTransferObjects/EmailEventData.php` - Readonly DTO for event data
- `config/email-tracker.php` - New config file
- `CHANGELOG.md`
- `UPGRADE.md`
- `phpstan.neon` - Static analysis config
- `pint.json` - Laravel Pint code style config

### Modified Files (from original)
- All files get namespace change
- `src/Migrations/*.php` - Configurable prefix + provider column
- `src/Models/*.php` - Add provider scope/column
- `src/TrackedMailer.php` - Renamed from SesMailer, add Mailable fix
- `src/routes.php` - Dynamic webhook route pattern
- `src/Controllers/OpenController.php` - Namespace only
- `src/Controllers/LinkController.php` - Namespace only

---

## Verification Plan

### Local Testing
```bash
cd ~/src/laravel-email-tracker
composer install
composer test
```

### Integration Testing in swingular-platform
```bash
cd ~/src/swingular-platform/apps/api

# composer.json
{
  "require": {
    "r0bdiabl0/laravel-email-tracker": "dev-main"
  },
  "repositories": [
    {"type": "path", "url": "../../laravel-email-tracker"}
  ]
}

composer update r0bdiabl0/laravel-email-tracker

# Update imports from Juhasev\LaravelSes to R0bdiabl0\EmailTracker
# Or use SesMail alias for minimal changes

php artisan test --filter=Email
```

### Manual Testing
1. Send email via SES with tracking
2. Verify `sent_emails` table has `provider='ses'`
3. Test SNS webhooks at new route paths
4. Test beacon/link tracking

---

## Migration Guide for swingular-platform

### Option A: Minimal Changes (Use Aliases)
1. Replace `juhasev/laravel-ses` with `r0bdiabl0/laravel-email-tracker`
2. `SesMail::` continues to work (aliased to `EmailTracker::`)
3. Run migration command: `php artisan email-tracker:migrate-from-ses`
4. Update SNS webhook URLs in AWS console

### Option B: Full Migration
1. Replace package
2. Find/replace: `Juhasev\LaravelSes` → `R0bdiabl0\EmailTracker`
3. Find/replace: `SesMail::` → `EmailTracker::`
4. Update config from `laravelses.php` to `email-tracker.php`
5. Run migration command
6. Update SNS webhook URLs

### Files to Update in swingular-platform
- `app/Notifications/Channels/SesMailChannel.php` - Can simplify using built-in channel
- `app/Mail/Concerns/TracksWithSes.php` - Can use built-in trait instead
- `app/Listeners/ConfigureSesMailTracking.php` - Update namespace
- Config files

---

## Success Criteria
- [ ] Package installs via Composer
- [ ] All existing tests pass
- [ ] Laravel 12 compatible
- [ ] Configurable table prefix works (including no prefix)
- [ ] Provider column populated correctly
- [ ] SES webhooks work at new routes
- [ ] SesMail alias works for backwards compat
- [ ] swingular-platform works with new package
- [ ] Migration command successfully converts old tables

---

## Future Enhancements (Post v1.0)
- Resend webhook implementation
- Postal webhook implementation
- Mailgun webhook implementation
- AWS SES v2 API features (VDM, delivery timing)
- Admin dashboard package (Filament/Nova)
