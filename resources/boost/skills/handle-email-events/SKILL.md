---
name: handle-email-events
description: Create event listeners for email tracking events like bounces, complaints, opens, clicks, and unsubscribes.
---

# Handle Email Events

## When to use this skill

Use this skill when:
- Handling bounce notifications to mark emails as invalid
- Processing spam complaints to unsubscribe users
- Responding to one-click unsubscribe requests
- Tracking email opens or link clicks for analytics
- Logging email delivery confirmations

## Available Events

| Event | Property | Description |
|-------|----------|-------------|
| `EmailSentEvent` | `$sentEmail` | Email was sent |
| `EmailDeliveryEvent` | `$sentEmail` | Delivery confirmed |
| `EmailBounceEvent` | `$emailBounce` | Email bounced |
| `EmailComplaintEvent` | `$emailComplaint` | Spam complaint received |
| `EmailOpenEvent` | `$emailOpen` | Email was opened |
| `EmailLinkClickEvent` | `$emailLink` | Link was clicked |
| `EmailUnsubscribeEvent` | `$email`, `$sentEmail` | Unsubscribe request |

## Register Listeners

In `EventServiceProvider`:

```php
use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;
use R0bdiabl0\EmailTracker\Events\EmailComplaintEvent;
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

## Bounce Handler Example

```php
namespace App\Listeners;

use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HandleEmailBounce
{
    public function handle(EmailBounceEvent $event): void
    {
        $bounce = $event->emailBounce;

        if ($bounce->type === 'Permanent') {
            User::where('email', $bounce->email)
                ->update(['email_valid' => false]);
        }

        Log::warning('Email bounced', [
            'email' => $bounce->email,
            'type' => $bounce->type,
            'provider' => $bounce->provider,
        ]);
    }
}
```

## Complaint Handler Example

```php
namespace App\Listeners;

use R0bdiabl0\EmailTracker\Events\EmailComplaintEvent;
use App\Models\User;

class HandleEmailComplaint
{
    public function handle(EmailComplaintEvent $event): void
    {
        $complaint = $event->emailComplaint;

        User::where('email', $complaint->email)
            ->update(['marketing_emails' => false]);
    }
}
```

## Unsubscribe Handler Example

```php
namespace App\Listeners;

use R0bdiabl0\EmailTracker\Events\EmailUnsubscribeEvent;
use App\Models\User;

class HandleUnsubscribe
{
    public function handle(EmailUnsubscribeEvent $event): void
    {
        User::where('email', $event->email)
            ->update(['marketing_emails' => false]);

        // Per-list unsubscribe based on batch
        if ($event->sentEmail?->batch) {
            MailingListSubscription::where('email', $event->email)
                ->where('list', $event->sentEmail->batch->name)
                ->delete();
        }
    }
}
```

## Open & Click Handler Examples

```php
use R0bdiabl0\EmailTracker\Events\EmailOpenEvent;
use R0bdiabl0\EmailTracker\Events\EmailLinkClickEvent;

class HandleEmailOpen
{
    public function handle(EmailOpenEvent $event): void
    {
        $open = $event->emailOpen;
        $sentEmail = $open->sentEmail;

        // Track for analytics
        Analytics::track('email_opened', [
            'email' => $sentEmail->email,
            'batch' => $sentEmail->batch?->name,
        ]);
    }
}

class HandleLinkClick
{
    public function handle(EmailLinkClickEvent $event): void
    {
        $link = $event->emailLink;

        Log::info('Link clicked', [
            'url' => $link->original_url,
            'clicks' => $link->click_count,
        ]);
    }
}
```

## Accessing Metadata

Provider webhook payloads are in the `metadata` property:

```php
public function handle(EmailBounceEvent $event): void
{
    // Note: metadata is always available in events
    // Only persisted to DB when EMAIL_TRACKER_STORE_METADATA=true
    $metadata = $event->emailBounce->metadata;

    $diagnosticCode = match ($event->emailBounce->provider) {
        'ses' => $metadata['bounce']['bouncedRecipients'][0]['diagnosticCode'] ?? null,
        'mailgun' => $metadata['event-data']['delivery-status']['message'] ?? null,
        'sendgrid' => $metadata['reason'] ?? null,
        'postmark' => $metadata['Description'] ?? null,
        default => null,
    };
}
```
