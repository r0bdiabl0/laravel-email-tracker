<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;
use R0bdiabl0\EmailTracker\Events\EmailComplaintEvent;
use R0bdiabl0\EmailTracker\Events\EmailDeliveryEvent;
use R0bdiabl0\EmailTracker\ModelResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resend webhook provider.
 *
 * Resend uses Svix for webhook delivery and signature verification.
 *
 * @see https://resend.com/docs/dashboard/webhooks/introduction
 */
class ResendProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'resend';
    }

    /**
     * Handle incoming webhook from Resend.
     *
     * Resend webhook events:
     * - email.sent: Email was successfully sent
     * - email.delivered: Email was delivered to recipient
     * - email.delivery_delayed: Email delivery is delayed
     * - email.complained: Recipient marked email as spam
     * - email.bounced: Email bounced (hard or soft)
     * - email.opened: Email was opened
     * - email.clicked: Link in email was clicked
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        $this->logRawPayload($request);

        // Validate Svix signature
        if (! $this->validateSignature($request)) {
            $this->logError('Webhook signature validation failed');

            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $eventType = $payload['type'] ?? $event ?? 'unknown';

        $this->logDebug("Processing Resend event: {$eventType}");

        return match ($eventType) {
            'email.bounced' => $this->handleBounce($payload),
            'email.complained' => $this->handleComplaint($payload),
            'email.delivered' => $this->handleDelivery($payload),
            'email.sent', 'email.opened', 'email.clicked', 'email.delivery_delayed' => $this->handleGenericEvent($payload, $eventType),
            default => response()->json(['success' => true, 'message' => 'Event type not tracked']),
        };
    }

    /**
     * Handle bounce event.
     */
    protected function handleBounce(array $payload): JsonResponse
    {
        $data = $payload['data'] ?? [];
        $messageId = $this->extractMessageId($data);

        if (! $messageId) {
            $this->logError('Bounce notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('bounce_tracking', true)
                ->firstOrFail();

            $email = $data['to'][0] ?? '';

            $emailBounce = ModelResolver::get('email_bounce')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $this->determineBounceType($data),
                'email' => $email,
                'bounced_at' => isset($data['created_at']) ? Carbon::parse($data['created_at']) : now(),
            ]);

            event(new EmailBounceEvent($emailBounce));

            $this->logDebug("Bounce processed for: {$email}");

            return response()->json(['success' => true, 'message' => 'Bounce processed']);
        } catch (ModelNotFoundException) {
            $this->logDebug("Message ID ({$messageId}) not found or bounce tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process bounce: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle complaint (spam report) event.
     */
    protected function handleComplaint(array $payload): JsonResponse
    {
        $data = $payload['data'] ?? [];
        $messageId = $this->extractMessageId($data);

        if (! $messageId) {
            $this->logError('Complaint notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('complaint_tracking', true)
                ->firstOrFail();

            $email = $data['to'][0] ?? '';

            $emailComplaint = ModelResolver::get('email_complaint')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => 'spam',
                'email' => $email,
                'complained_at' => isset($data['created_at']) ? Carbon::parse($data['created_at']) : now(),
            ]);

            event(new EmailComplaintEvent($emailComplaint));

            $this->logDebug("Complaint processed for: {$email}");

            return response()->json(['success' => true, 'message' => 'Complaint processed']);
        } catch (ModelNotFoundException) {
            $this->logDebug("Message ID ({$messageId}) not found or complaint tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process complaint: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle delivery event.
     */
    protected function handleDelivery(array $payload): JsonResponse
    {
        $data = $payload['data'] ?? [];
        $messageId = $this->extractMessageId($data);

        if (! $messageId) {
            $this->logError('Delivery notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('delivery_tracking', true)
                ->firstOrFail();

            $timestamp = isset($data['created_at']) ? Carbon::parse($data['created_at']) : now();
            $sentEmail->setDeliveredAt($timestamp);

            event(new EmailDeliveryEvent($sentEmail));

            $this->logDebug("Delivery processed for message: {$messageId}");

            return response()->json(['success' => true, 'message' => 'Delivery processed']);
        } catch (ModelNotFoundException) {
            $this->logDebug("Message ID ({$messageId}) not found or delivery tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process delivery: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle generic events (sent, opened, clicked, etc.) - log only for now.
     */
    protected function handleGenericEvent(array $payload, string $eventType): JsonResponse
    {
        $data = $payload['data'] ?? [];
        $messageId = $this->extractMessageId($data);

        $this->logDebug("Received {$eventType} event for message: {$messageId}");

        return response()->json(['success' => true, 'message' => "Event {$eventType} acknowledged"]);
    }

    /**
     * Extract message ID from Resend payload.
     */
    protected function extractMessageId(array $data): ?string
    {
        // Resend uses 'email_id' as the message identifier
        return $data['email_id'] ?? null;
    }

    /**
     * Determine bounce type from Resend payload.
     */
    protected function determineBounceType(array $data): string
    {
        // Resend doesn't provide detailed bounce classification
        // Default to Permanent for bounces
        return 'Permanent';
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
            timestamp: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature using Svix.
     *
     * Resend uses Svix for webhook signing. The signature is verified using:
     * - svix-id: Unique message identifier
     * - svix-timestamp: Unix timestamp of when the message was sent
     * - svix-signature: Base64-encoded HMAC-SHA256 signature
     */
    public function validateSignature(Request $request): bool
    {
        $secret = $this->getConfig('webhook_secret');

        if (! $secret) {
            // If no secret configured, skip validation (development mode)
            return true;
        }

        $signature = $request->header('svix-signature');
        $timestamp = $request->header('svix-timestamp');
        $webhookId = $request->header('svix-id');

        if (! $signature || ! $timestamp || ! $webhookId) {
            $this->logError('Missing Svix headers for signature validation');

            return false;
        }

        // Check timestamp is not too old (5 minutes tolerance)
        if (abs(time() - (int) $timestamp) > 300) {
            $this->logError('Webhook timestamp is too old');

            return false;
        }

        $payload = $request->getContent();
        $signedContent = "{$webhookId}.{$timestamp}.{$payload}";

        // Secret may be prefixed with 'whsec_'
        $secretBytes = base64_decode(str_replace('whsec_', '', $secret));

        // Svix signature format: "v1,<signature1> v1,<signature2>"
        $expectedSignatures = explode(' ', $signature);

        foreach ($expectedSignatures as $expectedSignature) {
            $parts = explode(',', $expectedSignature);
            if (count($parts) !== 2) {
                continue;
            }

            [$version, $sig] = $parts;
            if ($version !== 'v1') {
                continue;
            }

            $computedSignature = base64_encode(
                hash_hmac('sha256', $signedContent, $secretBytes, true),
            );

            if (hash_equals($sig, $computedSignature)) {
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
