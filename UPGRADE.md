# Upgrading from juhasev/laravel-ses

This guide covers migrating from `juhasev/laravel-ses` to `r0bdiabl0/laravel-email-tracker`.

## Quick Migration

### Option A: Automatic Migration (Recommended)

1. Install the new package alongside the old one:

```bash
composer require r0bdiabl0/laravel-email-tracker
```

2. Run the migration command:

```bash
# Preview what will change
php artisan email-tracker:migrate-from-ses --dry-run

# Run with backups
php artisan email-tracker:migrate-from-ses --backup

# Also update code namespaces automatically
php artisan email-tracker:migrate-from-ses --backup --update-code
```

3. Remove the old package:

```bash
composer remove juhasev/laravel-ses
```

4. Update your AWS SNS webhook URLs (see below)

### Option B: Minimal Changes (Use Aliases)

The package provides backwards compatibility aliases:

1. Replace the package:

```bash
composer remove juhasev/laravel-ses
composer require r0bdiabl0/laravel-email-tracker
```

2. Run migrations:

```bash
php artisan email-tracker:migrate-from-ses
```

3. Your existing `SesMail::` calls will continue to work!

## What Changes

### Namespace Changes

| Old | New |
|-----|-----|
| `Juhasev\LaravelSes\*` | `R0bdiabl0\EmailTracker\*` |
| `SesMail::` (facade) | `EmailTracker::` (or keep using `SesMail::` - it's aliased) |

### Table Names

| Old | New (default) |
|-----|---------------|
| `laravel_ses_sent_emails` | `sent_emails` |
| `laravel_ses_email_bounces` | `email_bounces` |
| `laravel_ses_email_complaints` | `email_complaints` |
| `laravel_ses_email_opens` | `email_opens` |
| `laravel_ses_email_links` | `email_links` |
| `laravel_ses_batches` | `batches` |

You can set a custom prefix via `EMAIL_TRACKER_TABLE_PREFIX` environment variable.

### Webhook URLs

| Old URL | New URL |
|---------|---------|
| `/ses/notification/bounce` | `/email-tracker/webhook/ses/bounce` |
| `/ses/notification/complaint` | `/email-tracker/webhook/ses/complaint` |
| `/ses/notification/delivery` | `/email-tracker/webhook/ses/delivery` |
| `/ses/beacon/{id}` | `/email-tracker/beacon/{id}` |
| `/ses/link/{id}` | `/email-tracker/link/{id}` |

To keep old URLs working during transition:

```env
EMAIL_TRACKER_LEGACY_ROUTES=true
```

### Configuration File

| Old | New |
|-----|-----|
| `config/laravelses.php` | `config/email-tracker.php` |

Publish the new config and migrate your settings:

```bash
php artisan vendor:publish --tag=email-tracker-config
```

## Step-by-Step Migration

### 1. Install New Package

```bash
composer require r0bdiabl0/laravel-email-tracker
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=email-tracker-config
```

### 3. Run Database Migration

```bash
# Dry run first
php artisan email-tracker:migrate-from-ses --dry-run

# With backup tables
php artisan email-tracker:migrate-from-ses --backup
```

### 4. Update Code Namespaces

Either update manually or use the command:

```bash
php artisan email-tracker:migrate-from-ses --update-code
```

Manual changes if needed:

```php
// Old
use Juhasev\LaravelSes\Facades\SesMail;

// New
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

// OR keep using SesMail (it's aliased)
use R0bdiabl0\EmailTracker\Facades\EmailTracker as SesMail;
```

### 5. Update AWS SNS Subscriptions

In AWS Console or via CLI:

```bash
# Update your SNS topic subscriptions with new URLs
aws sns subscribe \
    --topic-arn arn:aws:sns:us-east-1:xxx:ses-bounces \
    --protocol https \
    --notification-endpoint https://your-app.com/email-tracker/webhook/ses/bounce

aws sns subscribe \
    --topic-arn arn:aws:sns:us-east-1:xxx:ses-complaints \
    --protocol https \
    --notification-endpoint https://your-app.com/email-tracker/webhook/ses/complaint

aws sns subscribe \
    --topic-arn arn:aws:sns:us-east-1:xxx:ses-deliveries \
    --protocol https \
    --notification-endpoint https://your-app.com/email-tracker/webhook/ses/delivery
```

### 6. Remove Old Package

Once everything is working:

```bash
composer remove juhasev/laravel-ses
```

Delete old config file:

```bash
rm config/laravelses.php
```

### 7. Disable Legacy Routes (Optional)

Once all AWS SNS subscriptions are updated:

```env
EMAIL_TRACKER_LEGACY_ROUTES=false
```

## Rollback

If something goes wrong and you used the `--backup` flag:

```bash
php artisan email-tracker:rollback-migration
```

This will:
- Restore backup tables to their original names
- Drop the new tables

## New Features Available

After migration, you can take advantage of new features:

### Multi-Provider Support

```php
// Use different providers
EmailTracker::setProvider('resend')
    ->enableAllTracking()
    ->to($email)
    ->send($mailable);
```

### Email Validation

```php
// Automatically skip bounced addresses
// config/email-tracker.php
'validation' => [
    'skip_bounced' => true,
    'skip_complained' => true,
],
```

### TracksWithEmail Trait

```php
use R0bdiabl0\EmailTracker\Traits\TracksWithEmail;

class WelcomeMail extends Mailable
{
    use TracksWithEmail;
}

WelcomeMail::sendTracked('user@example.com', batch: 'welcome');
```

### Notification Channel

```php
use R0bdiabl0\EmailTracker\Notifications\EmailTrackerChannel;

public function via($notifiable): array
{
    return [EmailTrackerChannel::class];
}
```

## Troubleshooting

### Tables Already Exist

If new tables already exist, the migration will skip them. Either:
- Drop the new tables first
- Manually rename the old tables

### Missing Provider Column

If migrated tables don't have the `provider` column, run:

```php
Schema::table('sent_emails', function ($table) {
    $table->string('provider')->default('ses')->after('id')->index();
});
```

### Legacy Routes Not Working

Make sure you've enabled them:

```env
EMAIL_TRACKER_LEGACY_ROUTES=true
```

And clear config cache:

```bash
php artisan config:clear
```

## Questions?

Open an issue at: https://github.com/r0bdiabl0/laravel-email-tracker/issues
