<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use Symfony\Component\HttpFoundation\Response;

class SendgridProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'sendgrid';
    }

    /**
     * Handle incoming webhook from SendGrid.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        // Validate webhook signature
        if (! $this->validateSignature($request)) {
            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $events = $request->all();
        $this->logRawPayload($request);

        // SendGrid sends an array of events
        foreach ($events as $eventData) {
            $eventType = $eventData['event'] ?? 'unknown';

            // TODO: Implement SendGrid webhook handling
            // SendGrid events: processed, dropped, delivered, deferred,
            // bounce, open, click, spamreport, unsubscribe, group_unsubscribe, group_resubscribe

            $this->logDebug("Received SendGrid event: {$eventType}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received (SendGrid handler not fully implemented)',
        ]);
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        return new EmailEventData(
            messageId: $payload['sg_message_id'] ?? '',
            email: $payload['email'] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($payload['event'] ?? 'unknown'),
            timestamp: isset($payload['timestamp']) ? \Carbon\Carbon::createFromTimestamp($payload['timestamp']) : null,
            bounceType: $payload['type'] ?? null,
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     */
    public function validateSignature(Request $request): bool
    {
        $verificationKey = $this->getConfig('verification_key');

        if (! $verificationKey) {
            // If no key configured, skip validation
            return true;
        }

        $signature = $request->header('X-Twilio-Email-Event-Webhook-Signature');
        $timestamp = $request->header('X-Twilio-Email-Event-Webhook-Timestamp');

        if (! $signature || ! $timestamp) {
            return false;
        }

        $payload = $request->getContent();
        $signedPayload = $timestamp.$payload;

        // SendGrid uses ECDSA with the verification key
        // For simplicity, using HMAC as a placeholder
        // TODO: Implement proper ECDSA verification

        return true; // Placeholder - implement proper verification
    }

    /**
     * Map SendGrid event type to EmailEventType.
     */
    protected function mapEventType(string $event): EmailEventType
    {
        return match ($event) {
            'processed' => EmailEventType::Sent,
            'delivered' => EmailEventType::Delivered,
            'bounce' => EmailEventType::Bounced,
            'spamreport' => EmailEventType::Complained,
            'open' => EmailEventType::Opened,
            'click' => EmailEventType::Clicked,
            'dropped' => EmailEventType::Rejected,
            default => EmailEventType::Sent,
        };
    }
}
