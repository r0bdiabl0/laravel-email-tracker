## Laravel Email Tracker

Multi-provider email tracking and bounce management for Laravel 11+. Tracks opens, clicks, bounces, complaints, and deliveries across AWS SES, Resend, Postal, Mailgun, SendGrid, and Postmark.

### Key Features

- **Open & Click Tracking**: Tracking pixel for opens, link rewriting for clicks.
- **Bounce/Complaint Handling**: Webhooks for all major providers with signature validation.
- **Suppression**: Automatically skip sending to bounced/complained addresses.
- **One-Click Unsubscribe**: RFC 8058 compliant List-Unsubscribe headers.
- **Batch Grouping**: Organize emails into named campaigns.
- **Events**: Laravel events for all tracking activities.

### Sending Tracked Emails

@verbatim
<code-snippet name="Send tracked email with EmailTracker facade" lang="php">
use R0bdiabl0\EmailTracker\Facades\EmailTracker;

// Enable all tracking (opens, links, bounces, complaints, deliveries)
EmailTracker::enableAllTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// With batch grouping for campaigns
EmailTracker::enableAllTracking()
    ->setBatch('welcome-campaign-2024')
    ->to('user@example.com')
    ->send(new WelcomeMail($user));

// With unsubscribe headers for marketing emails
EmailTracker::enableAllTracking()
    ->enableUnsubscribeHeaders()
    ->to('user@example.com')
    ->send(new MarketingMail($user));

// Use a specific provider
EmailTracker::provider('resend')
    ->enableAllTracking()
    ->to('user@example.com')
    ->send(new WelcomeMail($user));
</code-snippet>
@endverbatim

### TracksWithEmail Trait

@verbatim
<code-snippet name="Using TracksWithEmail trait on Mailables" lang="php">
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

// Static convenience methods
WelcomeMail::sendTracked('user@example.com', batch: 'welcome');
WelcomeMail::queueTracked(['user@example.com'], batch: 'welcome', queue: 'emails');
</code-snippet>
@endverbatim

### Notification Channel

@verbatim
<code-snippet name="Using EmailTrackerChannel for notifications" lang="php">
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
</code-snippet>
@endverbatim

### Listening to Events

@verbatim
<code-snippet name="Handle email events with listeners" lang="php">
use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;
use R0bdiabl0\EmailTracker\Events\EmailComplaintEvent;
use R0bdiabl0\EmailTracker\Events\EmailUnsubscribeEvent;

// In EventServiceProvider
protected $listen = [
    EmailBounceEvent::class => [HandleEmailBounce::class],
    EmailComplaintEvent::class => [HandleEmailComplaint::class],
    EmailUnsubscribeEvent::class => [HandleUnsubscribe::class],
];

// Example listener
class HandleEmailBounce
{
    public function handle(EmailBounceEvent $event): void
    {
        $bounce = $event->emailBounce;
        if ($bounce->type === 'Permanent') {
            User::where('email', $bounce->email)
                ->update(['email_valid' => false]);
        }
    }
}
</code-snippet>
@endverbatim

### Querying Tracked Data

@verbatim
<code-snippet name="Query sent emails and bounces" lang="php">
use R0bdiabl0\EmailTracker\Models\SentEmail;
use R0bdiabl0\EmailTracker\Models\Batch;

// Get bounced or complained emails
$bounced = SentEmail::bounced()->get();
$complained = SentEmail::complained()->get();

// Filter by provider or email
$sesEmails = SentEmail::forProvider('ses')->get();
$userEmails = SentEmail::forEmail('user@example.com')->get();

// Get batch with emails
$batch = Batch::where('name', 'campaign-2024')
    ->with('sentEmails')
    ->first();
</code-snippet>
@endverbatim

### Suppression / Bounce Management

@verbatim
<code-snippet name="Check email suppression status" lang="php">
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
</code-snippet>
@endverbatim

### Custom Providers

@verbatim
<code-snippet name="Create and register a custom email provider" lang="php">
use R0bdiabl0\EmailTracker\Providers\AbstractProvider;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;

class CustomProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'custom';
    }

    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        if (! $this->validateSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $data = $this->parsePayload($request->all());

        return match ($request->input('event_type')) {
            'bounce' => $this->processBounceEvent($data),
            'complaint' => $this->processComplaintEvent($data),
            'delivered' => $this->processDeliveryEvent($data),
            default => response()->json(['success' => true]),
        };
    }

    public function parsePayload(array $payload): EmailEventData
    {
        return new EmailEventData(
            messageId: $payload['message_id'] ?? '',
            email: $payload['recipient'] ?? '',
            provider: $this->getName(),
            eventType: EmailEventType::Bounced,
            timestamp: Carbon::parse($payload['timestamp']),
            metadata: $payload,
        );
    }

    public function validateSignature(Request $request): bool
    {
        $secret = $this->getConfig('webhook_secret');
        if (! $secret) return true;

        $signature = $request->header('X-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature ?? '');
    }
}

// Register in AppServiceProvider boot()
EmailTracker::registerProvider('custom', CustomProvider::class);
</code-snippet>
@endverbatim

### Configuration

Key environment variables:
- `EMAIL_TRACKER_DEFAULT_PROVIDER`: Default provider (ses, resend, postal, mailgun, sendgrid, postmark)
- `EMAIL_TRACKER_SKIP_BOUNCED`: Enable bounce suppression
- `EMAIL_TRACKER_SKIP_COMPLAINED`: Enable complaint suppression
- `EMAIL_TRACKER_UNSUBSCRIBE_ENABLED`: Enable RFC 8058 headers globally
- `EMAIL_TRACKER_DEBUG`: Enable debug logging

### Database Tables

Tables created (with optional `EMAIL_TRACKER_TABLE_PREFIX`):
- `sent_emails`: Tracked email records with message_id and provider
- `email_bounces`: Bounce records with type (Permanent/Transient)
- `email_complaints`: Spam complaint records
- `email_opens`: Open tracking records
- `email_links`: Click tracking records
- `batches`: Campaign batch groupings

### Webhook Endpoints

Provider webhooks are at `/email-tracker/webhook/{provider}`:
- SES: `/email-tracker/webhook/ses/bounce`, `/ses/complaint`, `/ses/delivery`
- Resend: `/email-tracker/webhook/resend`
- Postal: `/email-tracker/webhook/postal`
- Mailgun: `/email-tracker/webhook/mailgun`
- SendGrid: `/email-tracker/webhook/sendgrid`
- Postmark: `/email-tracker/webhook/postmark`

### Admin Panel Plugins

@verbatim
<code-snippet name="Install admin panel plugins" lang="bash">
# Filament plugin for dashboard widgets and resource pages
composer require r0bdiabl0/laravel-email-tracker-filament

# Nova plugin for resource management
composer require r0bdiabl0/laravel-email-tracker-nova
</code-snippet>
@endverbatim
