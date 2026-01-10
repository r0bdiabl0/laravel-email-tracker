<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use Symfony\Component\HttpFoundation\Response;

class PostmarkProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'postmark';
    }

    /**
     * Handle incoming webhook from Postmark.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        // Validate webhook
        if (! $this->validateSignature($request)) {
            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $this->logRawPayload($request);

        $eventType = $payload['RecordType'] ?? $event ?? 'unknown';

        // TODO: Implement Postmark webhook handling
        // Postmark events: Delivery, Bounce, SpamComplaint, Open, Click,
        // SubscriptionChange, LinkClick

        $this->logDebug("Received Postmark event: {$eventType}");

        return response()->json([
            'success' => true,
            'message' => 'Webhook received (Postmark handler not fully implemented)',
        ]);
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        return new EmailEventData(
            messageId: $payload['MessageID'] ?? '',
            email: $payload['Recipient'] ?? $payload['Email'] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($payload['RecordType'] ?? 'unknown'),
            timestamp: isset($payload['DeliveredAt']) ? \Carbon\Carbon::parse($payload['DeliveredAt']) : null,
            bounceType: $payload['Type'] ?? null,
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     */
    public function validateSignature(Request $request): bool
    {
        $webhookToken = $this->getConfig('webhook_token');

        if (! $webhookToken) {
            // If no token configured, skip validation
            return true;
        }

        // Postmark can use a webhook token or basic auth
        $providedToken = $request->header('X-Postmark-Webhook-Token');

        if ($providedToken) {
            return hash_equals($webhookToken, $providedToken);
        }

        return true; // Fallback - skip validation if no token provided
    }

    /**
     * Map Postmark event type to EmailEventType.
     */
    protected function mapEventType(string $recordType): EmailEventType
    {
        return match ($recordType) {
            'Delivery' => EmailEventType::Delivered,
            'Bounce' => EmailEventType::Bounced,
            'SpamComplaint' => EmailEventType::Complained,
            'Open' => EmailEventType::Opened,
            'Click', 'LinkClick' => EmailEventType::Clicked,
            default => EmailEventType::Sent,
        };
    }
}
