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
use R0bdiabl0\EmailTracker\Events\EmailDeliveryEvent;
use R0bdiabl0\EmailTracker\ModelResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Postal webhook provider.
 *
 * Postal is an open-source mail delivery platform.
 *
 * Note: Postal does not support spam complaint webhooks. If you need complaint
 * tracking, consider using a provider like SES, Postmark, or SendGrid that
 * receives feedback loop reports from ISPs.
 *
 * @see https://docs.postalserver.io/developer/webhooks
 */
class PostalProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'postal';
    }

    /**
     * Handle incoming webhook from Postal.
     *
     * Postal webhook events:
     * - MessageSent: Message was accepted by Postal
     * - MessageDelivered: Message was delivered to recipient's mail server
     * - MessageDelayed: Message delivery is delayed (temporary failure)
     * - MessageBounced: Message bounced (hard bounce)
     * - MessageHeld: Message was held for review
     * - MessageLinkClicked: Recipient clicked a link
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        $this->logRawPayload($request);

        // Validate webhook key
        if (! $this->validateSignature($request)) {
            $this->logError('Webhook signature validation failed');

            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $eventType = $payload['event'] ?? $event ?? 'unknown';

        $this->logDebug("Processing Postal event: {$eventType}");

        return match ($eventType) {
            'MessageBounced' => $this->handleBounce($payload),
            'MessageDelivered' => $this->handleDelivery($payload),
            'MessageSent', 'MessageDelayed', 'MessageHeld', 'MessageLinkClicked' => $this->handleGenericEvent($payload, $eventType),
            default => response()->json(['success' => true, 'message' => 'Event type not tracked']),
        };
    }

    /**
     * Handle bounce event.
     */
    protected function handleBounce(array $payload): JsonResponse
    {
        $message = $payload['message'] ?? [];
        $messageId = $this->extractMessageId($message);

        if (! $messageId) {
            $this->logError('Bounce notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('bounce_tracking', true)
                ->firstOrFail();

            $email = $message['rcpt_to'] ?? '';

            $storeMetadata = config('email-tracker.store_metadata', false);

            $emailBounce = ModelResolver::get('email_bounce')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $this->determineBounceType($payload),
                'email' => $email,
                'bounced_at' => isset($payload['timestamp']) ? Carbon::createFromTimestamp($payload['timestamp']) : now(),
                'metadata' => $storeMetadata ? $payload : null,
            ]);

            // Ensure metadata is available in event even if not persisted
            if (! $storeMetadata) {
                $emailBounce->setAttribute('metadata', $payload);
            }

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
     * Handle delivery event.
     */
    protected function handleDelivery(array $payload): JsonResponse
    {
        $message = $payload['message'] ?? [];
        $messageId = $this->extractMessageId($message);

        if (! $messageId) {
            $this->logError('Delivery notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('delivery_tracking', true)
                ->firstOrFail();

            $timestamp = isset($payload['timestamp']) ? Carbon::createFromTimestamp($payload['timestamp']) : now();
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
        $message = $payload['message'] ?? [];
        $messageId = $this->extractMessageId($message) ?? 'unknown';

        $this->logDebug("Received {$eventType} event for message: {$messageId}");

        return response()->json(['success' => true, 'message' => "Event {$eventType} acknowledged"]);
    }

    /**
     * Extract message ID from Postal webhook payload.
     *
     * Postal returns its own message ID, but we pass our tracking message ID
     * in the X-Message-ID header which is included in webhook payloads.
     * We check for our custom header first, then fall back to Postal's ID.
     */
    protected function extractMessageId(array $message): ?string
    {
        // First check for our custom X-Message-ID header (set by PostalTransport)
        // Postal includes custom headers in the webhook payload
        $headers = $message['headers'] ?? [];
        if (isset($headers['x-message-id'])) {
            // Strip angle brackets if present (e.g., "<uuid@domain>" -> "uuid@domain")
            return trim($headers['x-message-id'], '<>');
        }

        // Fall back to Postal's message ID (won't match our records, but log it)
        return $message['id'] ?? $message['message_id'] ?? null;
    }

    /**
     * Determine bounce type from Postal payload.
     */
    protected function determineBounceType(array $payload): string
    {
        // Postal bounces are typically hard bounces
        $output = $payload['output'] ?? '';

        if (str_contains(strtolower($output), 'permanent') || str_contains(strtolower($output), '550')) {
            return 'Permanent';
        }

        if (str_contains(strtolower($output), 'temporary') || str_contains(strtolower($output), '451')) {
            return 'Transient';
        }

        return 'Permanent';
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        $message = $payload['message'] ?? [];

        return new EmailEventData(
            messageId: $message['id'] ?? $message['message_id'] ?? '',
            email: $message['rcpt_to'] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($payload['event'] ?? 'unknown'),
            timestamp: isset($payload['timestamp']) ? Carbon::createFromTimestamp($payload['timestamp']) : null,
            bounceType: $this->determineBounceType($payload),
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     *
     * Postal uses a simple header-based validation with a shared key.
     */
    public function validateSignature(Request $request): bool
    {
        $webhookKey = $this->getConfig('webhook_key');

        if (! $webhookKey) {
            // If no key configured, skip validation (development mode)
            return true;
        }

        // Postal sends the key in the X-Postal-Webhook-Key header
        $providedKey = $request->header('X-Postal-Webhook-Key');

        if (! $providedKey) {
            $this->logError('Missing X-Postal-Webhook-Key header');

            return false;
        }

        return hash_equals($webhookKey, $providedKey);
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
