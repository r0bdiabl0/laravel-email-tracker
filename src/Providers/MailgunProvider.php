<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use Symfony\Component\HttpFoundation\Response;

class MailgunProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'mailgun';
    }

    /**
     * Handle incoming webhook from Mailgun.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        // Validate webhook signature
        if (! $this->validateSignature($request)) {
            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $this->logRawPayload($request);

        $eventData = $payload['event-data'] ?? [];
        $eventType = $eventData['event'] ?? $event ?? 'unknown';

        // TODO: Implement Mailgun webhook handling
        // Mailgun events: accepted, delivered, failed, opened, clicked,
        // unsubscribed, complained, stored

        $this->logDebug("Received Mailgun event: {$eventType}");

        return response()->json([
            'success' => true,
            'message' => 'Webhook received (Mailgun handler not fully implemented)',
        ]);
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        $eventData = $payload['event-data'] ?? [];
        $message = $eventData['message'] ?? [];

        return new EmailEventData(
            messageId: $message['headers']['message-id'] ?? '',
            email: $eventData['recipient'] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($eventData['event'] ?? 'unknown'),
            timestamp: isset($eventData['timestamp']) ? \Carbon\Carbon::createFromTimestamp($eventData['timestamp']) : null,
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     */
    public function validateSignature(Request $request): bool
    {
        $signingKey = $this->getConfig('webhook_signing_key');

        if (! $signingKey) {
            // If no key configured, skip validation
            return true;
        }

        $signature = $request->input('signature', []);
        $timestamp = $signature['timestamp'] ?? '';
        $token = $signature['token'] ?? '';
        $providedSignature = $signature['signature'] ?? '';

        if (! $timestamp || ! $token || ! $providedSignature) {
            return false;
        }

        // Verify timestamp is not too old (5 minutes)
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $timestamp.$token, $signingKey);

        return hash_equals($computedSignature, $providedSignature);
    }

    /**
     * Map Mailgun event type to EmailEventType.
     */
    protected function mapEventType(string $event): EmailEventType
    {
        return match ($event) {
            'accepted' => EmailEventType::Sent,
            'delivered' => EmailEventType::Delivered,
            'failed' => EmailEventType::Bounced,
            'complained' => EmailEventType::Complained,
            'opened' => EmailEventType::Opened,
            'clicked' => EmailEventType::Clicked,
            'rejected' => EmailEventType::Rejected,
            default => EmailEventType::Sent,
        };
    }
}
