---
name: create-email-provider
description: Create custom email provider integrations for unsupported email services.
---

# Create Email Provider

## When to use this skill

Use this skill when:
- Integrating an email service not supported by the package
- Creating a custom webhook handler for your email provider
- Extending the package with proprietary email systems

## Create the Provider Class

Create `app/EmailProviders/{ProviderName}Provider.php`:

```php
namespace App\EmailProviders;

use Carbon\Carbon;
use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use R0bdiabl0\EmailTracker\Providers\AbstractProvider;
use Symfony\Component\HttpFoundation\Response;

class CustomProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'custom';
    }

    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        $this->logRawPayload($request);

        if (! $this->validateSignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $data = $this->parsePayload($payload);

        return match ($payload['event_type'] ?? '') {
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
            eventType: $this->mapEventType($payload['event_type'] ?? ''),
            timestamp: Carbon::parse($payload['timestamp'] ?? now()),
            bounceType: $this->mapBounceType($payload['bounce_type'] ?? null),
            metadata: $payload,
        );
    }

    public function validateSignature(Request $request): bool
    {
        $secret = $this->getConfig('webhook_secret');
        if (! $secret) return true;

        $signature = $request->header('X-Webhook-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature ?? '');
    }

    protected function mapEventType(string $event): EmailEventType
    {
        return match (strtolower($event)) {
            'bounce', 'hard_bounce' => EmailEventType::Bounced,
            'complaint', 'spam' => EmailEventType::Complained,
            'delivered' => EmailEventType::Delivered,
            default => EmailEventType::Sent,
        };
    }

    protected function mapBounceType(?string $type): ?string
    {
        return match (strtolower($type ?? '')) {
            'hard', 'permanent' => 'Permanent',
            'soft', 'transient' => 'Transient',
            default => $type,
        };
    }
}
```

## Register the Provider

In `AppServiceProvider`:

```php
use R0bdiabl0\EmailTracker\Facades\EmailTracker;
use App\EmailProviders\CustomProvider;

public function boot(): void
{
    EmailTracker::registerProvider('custom', CustomProvider::class);
}
```

## Add Configuration

In `config/email-tracker.php`:

```php
'providers' => [
    'custom' => [
        'enabled' => env('EMAIL_TRACKER_CUSTOM_ENABLED', true),
        'webhook_secret' => env('EMAIL_TRACKER_CUSTOM_SECRET'),
    ],
],
```

## Webhook Endpoint

Your provider's webhook is automatically available at:
```
POST https://your-app.com/email-tracker/webhook/custom
```

## Helper Methods

The `AbstractProvider` base class provides:

```php
// Logging
$this->logDebug('Processing');
$this->logError('Failed');
$this->logRawPayload($request);

// Configuration
$secret = $this->getConfig('webhook_secret');

// Event processing (creates DB records + fires events)
$this->processBounceEvent($data);
$this->processComplaintEvent($data);
$this->processDeliveryEvent($data);
```

## EmailEventData Properties

```php
new EmailEventData(
    messageId: string,
    email: string,
    provider: string,
    eventType: EmailEventType,
    timestamp: ?Carbon,
    bounceType: ?string,       // 'Permanent' or 'Transient'
    complaintType: ?string,
    diagnosticCode: ?string,   // SMTP diagnostic code
    metadata: array,
);
```

## Dependency Injection

Your provider supports constructor dependency injection:

```php
class CustomProvider extends AbstractProvider
{
    public function __construct(
        protected MyHttpClient $client,
        protected LoggerInterface $logger,
    ) {}
}
```
