---
name: send-tracked-email
description: Send tracked emails with Laravel Email Tracker, including open/click tracking, batches, and unsubscribe headers.
---

# Send Tracked Email

## When to use this skill

Use this skill when:
- Sending emails that need open, click, bounce, or complaint tracking
- Creating campaign emails with batch grouping
- Adding RFC 8058 unsubscribe headers to marketing emails
- Using a specific email provider (SES, Resend, Mailgun, etc.)

## Creating a Tracked Mailable

Add the `TracksWithEmail` trait to your Mailable:

```php
use Illuminate\Mail\Mailable;
use R0bdiabl0\EmailTracker\Traits\TracksWithEmail;

class WelcomeMail extends Mailable
{
    use TracksWithEmail;

    public function __construct(
        public User $user,
    ) {}

    public function build()
    {
        return $this->subject('Welcome!')
            ->view('emails.welcome');
    }
}
```

## Sending Tracked Emails

### Basic Tracking

```php
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

EmailTracker::enableAllTracking()
    ->to($user->email)
    ->send(new WelcomeMail($user));
```

### With Batch Grouping (Campaigns)

```php
EmailTracker::enableAllTracking()
    ->setBatch('welcome-campaign-2024')
    ->to($user->email)
    ->send(new WelcomeMail($user));
```

### With Unsubscribe Headers (Marketing)

```php
EmailTracker::enableAllTracking()
    ->enableUnsubscribeHeaders()
    ->setBatch('newsletter-jan-2024')
    ->to($user->email)
    ->send(new NewsletterMail($user));
```

### Specific Provider

```php
EmailTracker::provider('resend')
    ->enableAllTracking()
    ->to($user->email)
    ->send(new WelcomeMail($user));
```

## Quick Send Methods

With `TracksWithEmail` trait (for Mailables without required constructor args):

```php
// Send immediately
WelcomeMail::sendTracked('user@example.com', batch: 'welcome');

// Queue for background
WelcomeMail::queueTracked(
    ['user@example.com'],
    batch: 'welcome',
    queue: 'emails'
);
```

For Mailables with constructor arguments, override `getConstructorArgs()`:

```php
class WelcomeMail extends Mailable
{
    use TracksWithEmail;

    public function __construct(public User $user) {}

    protected static function getConstructorArgs(): array
    {
        return [auth()->user()]; // Provide constructor args
    }
}
```

## Tracking Options

| Method | Tracks |
|--------|--------|
| `enableAllTracking()` | Opens, links, bounces, complaints, deliveries |
| `enableOpenTracking()` | Email opens only |
| `enableLinkTracking()` | Link clicks only |
| `enableBounceTracking()` | Bounces only |
| `enableComplaintTracking()` | Spam complaints only |
| `enableDeliveryTracking()` | Delivery confirmations only |

## Notification Channel

```php
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
