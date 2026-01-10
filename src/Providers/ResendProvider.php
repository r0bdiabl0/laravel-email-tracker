<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use Symfony\Component\HttpFoundation\Response;

class ResendProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'resend';
    }

    /**
     * Handle incoming webhook from Resend.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        // Validate webhook signature
        if (! $this->validateSignature($request)) {
            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $this->logRawPayload($request);

        $eventType = $payload['type'] ?? $event ?? 'unknown';

        // TODO: Implement Resend webhook handling
        // Resend events: email.sent, email.delivered, email.delivery_delayed,
        // email.complained, email.bounced, email.opened, email.clicked

        $this->logDebug("Received Resend event: {$eventType}");

        return response()->json([
            'success' => true,
            'message' => 'Webhook received (Resend handler not fully implemented)',
        ]);
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        $data = $payload['data'] ?? [];

        return new EmailEventData(
            messageId: $data['email_id'] ?? '',
            email: $data['to'][0] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($payload['type'] ?? 'unknown'),
            timestamp: isset($data['created_at']) ? \Carbon\Carbon::parse($data['created_at']) : null,
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     */
    public function validateSignature(Request $request): bool
    {
        $secret = $this->getConfig('webhook_secret');

        if (! $secret) {
            // If no secret configured, skip validation
            return true;
        }

        $signature = $request->header('svix-signature');
        $timestamp = $request->header('svix-timestamp');
        $webhookId = $request->header('svix-id');

        if (! $signature || ! $timestamp || ! $webhookId) {
            return false;
        }

        // Resend uses Svix for webhooks
        $payload = $request->getContent();
        $signedContent = "{$webhookId}.{$timestamp}.{$payload}";

        $expectedSignatures = explode(' ', $signature);
        foreach ($expectedSignatures as $expectedSignature) {
            $parts = explode(',', $expectedSignature);
            if (count($parts) !== 2) {
                continue;
            }

            $computedSignature = base64_encode(
                hash_hmac('sha256', $signedContent, base64_decode($secret), true)
            );

            if (hash_equals($parts[1], $computedSignature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map Resend event type to EmailEventType.
     */
    protected function mapEventType(string $type): EmailEventType
    {
        return match ($type) {
            'email.sent' => EmailEventType::Sent,
            'email.delivered' => EmailEventType::Delivered,
            'email.bounced' => EmailEventType::Bounced,
            'email.complained' => EmailEventType::Complained,
            'email.opened' => EmailEventType::Opened,
            'email.clicked' => EmailEventType::Clicked,
            default => EmailEventType::Sent,
        };
    }
}
