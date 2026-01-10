<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use Symfony\Component\HttpFoundation\Response;

class PostalProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'postal';
    }

    /**
     * Handle incoming webhook from Postal.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        // Validate webhook
        if (! $this->validateSignature($request)) {
            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $this->logRawPayload($request);

        $eventType = $payload['event'] ?? $event ?? 'unknown';

        // TODO: Implement Postal webhook handling
        // Postal events: MessageSent, MessageDelivered, MessageDelayed,
        // MessageBounced, MessageHeld, MessageLinkClicked

        $this->logDebug("Received Postal event: {$eventType}");

        return response()->json([
            'success' => true,
            'message' => 'Webhook received (Postal handler not fully implemented)',
        ]);
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        return new EmailEventData(
            messageId: $payload['message']['id'] ?? '',
            email: $payload['message']['rcpt_to'] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($payload['event'] ?? 'unknown'),
            timestamp: isset($payload['timestamp']) ? \Carbon\Carbon::createFromTimestamp($payload['timestamp']) : null,
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     */
    public function validateSignature(Request $request): bool
    {
        $webhookKey = $this->getConfig('webhook_key');

        if (! $webhookKey) {
            // If no key configured, skip validation
            return true;
        }

        // Postal uses a simple key-based validation
        $providedKey = $request->header('X-Postal-Webhook-Key');

        return $providedKey && hash_equals($webhookKey, $providedKey);
    }

    /**
     * Map Postal event type to EmailEventType.
     */
    protected function mapEventType(string $event): EmailEventType
    {
        return match ($event) {
            'MessageSent' => EmailEventType::Sent,
            'MessageDelivered' => EmailEventType::Delivered,
            'MessageBounced' => EmailEventType::Bounced,
            'MessageLinkClicked' => EmailEventType::Clicked,
            default => EmailEventType::Sent,
        };
    }
}
