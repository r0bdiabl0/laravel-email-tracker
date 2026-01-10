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
 * Mailgun webhook provider.
 *
 * @see https://documentation.mailgun.com/en/latest/user_manual.html#webhooks-1
 */
class MailgunProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'mailgun';
    }

    /**
     * Handle incoming webhook from Mailgun.
     *
     * Mailgun webhook events:
     * - accepted: Message was accepted for delivery
     * - delivered: Message was delivered to recipient
     * - failed: Permanent failure (bounce)
     * - opened: Recipient opened the email
     * - clicked: Recipient clicked a link
     * - unsubscribed: Recipient unsubscribed
     * - complained: Recipient marked as spam
     * - stored: Message was stored
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        $this->logRawPayload($request);

        // Validate webhook signature
        if (! $this->validateSignature($request)) {
            $this->logError('Webhook signature validation failed');

            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $eventData = $payload['event-data'] ?? [];
        $eventType = $eventData['event'] ?? $event ?? 'unknown';

        $this->logDebug("Processing Mailgun event: {$eventType}");

        return match ($eventType) {
            'failed' => $this->handleBounce($payload),
            'complained' => $this->handleComplaint($payload),
            'delivered' => $this->handleDelivery($payload),
            'accepted', 'opened', 'clicked', 'unsubscribed', 'stored' => $this->handleGenericEvent($payload, $eventType),
            default => response()->json(['success' => true, 'message' => 'Event type not tracked']),
        };
    }

    /**
     * Handle bounce (failed) event.
     */
    protected function handleBounce(array $payload): JsonResponse
    {
        $eventData = $payload['event-data'] ?? [];
        $messageId = $this->extractMessageId($eventData);

        if (! $messageId) {
            $this->logError('Bounce notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('bounce_tracking', true)
                ->firstOrFail();

            $email = $eventData['recipient'] ?? '';

            $emailBounce = ModelResolver::get('email_bounce')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $this->determineBounceType($eventData),
                'email' => $email,
                'bounced_at' => isset($eventData['timestamp']) ? Carbon::createFromTimestamp($eventData['timestamp']) : now(),
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
     * Handle complaint event.
     */
    protected function handleComplaint(array $payload): JsonResponse
    {
        $eventData = $payload['event-data'] ?? [];
        $messageId = $this->extractMessageId($eventData);

        if (! $messageId) {
            $this->logError('Complaint notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('complaint_tracking', true)
                ->firstOrFail();

            $email = $eventData['recipient'] ?? '';

            $emailComplaint = ModelResolver::get('email_complaint')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => 'spam',
                'email' => $email,
                'complained_at' => isset($eventData['timestamp']) ? Carbon::createFromTimestamp($eventData['timestamp']) : now(),
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
        $eventData = $payload['event-data'] ?? [];
        $messageId = $this->extractMessageId($eventData);

        if (! $messageId) {
            $this->logError('Delivery notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('delivery_tracking', true)
                ->firstOrFail();

            $timestamp = isset($eventData['timestamp']) ? Carbon::createFromTimestamp($eventData['timestamp']) : now();
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
     * Handle generic events - log only for now.
     */
    protected function handleGenericEvent(array $payload, string $eventType): JsonResponse
    {
        $eventData = $payload['event-data'] ?? [];
        $messageId = $this->extractMessageId($eventData) ?? 'unknown';

        $this->logDebug("Received {$eventType} event for message: {$messageId}");

        return response()->json(['success' => true, 'message' => "Event {$eventType} acknowledged"]);
    }

    /**
     * Extract message ID from Mailgun event data.
     */
    protected function extractMessageId(array $eventData): ?string
    {
        $message = $eventData['message'] ?? [];
        $headers = $message['headers'] ?? [];

        // Message ID can be in headers or directly in message
        return $headers['message-id'] ?? $message['message-id'] ?? null;
    }

    /**
     * Determine bounce type from Mailgun event data.
     */
    protected function determineBounceType(array $eventData): string
    {
        $severity = $eventData['severity'] ?? '';
        $reason = $eventData['reason'] ?? '';

        if ($severity === 'permanent' || str_contains(strtolower($reason), 'bounce')) {
            return 'Permanent';
        }

        if ($severity === 'temporary') {
            return 'Transient';
        }

        return 'Permanent';
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
            timestamp: isset($eventData['timestamp']) ? Carbon::createFromTimestamp($eventData['timestamp']) : null,
            bounceType: $this->determineBounceType($eventData),
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     *
     * Mailgun signs webhooks using HMAC-SHA256.
     */
    public function validateSignature(Request $request): bool
    {
        $signingKey = $this->getConfig('webhook_signing_key');

        if (! $signingKey) {
            // If no key configured, skip validation (development mode)
            return true;
        }

        $signature = $request->input('signature', []);
        $timestamp = $signature['timestamp'] ?? '';
        $token = $signature['token'] ?? '';
        $providedSignature = $signature['signature'] ?? '';

        if (! $timestamp || ! $token || ! $providedSignature) {
            $this->logError('Missing signature parameters');

            return false;
        }

        // Verify timestamp is not too old (5 minutes)
        if (abs(time() - (int) $timestamp) > 300) {
            $this->logError('Webhook timestamp is too old');

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
