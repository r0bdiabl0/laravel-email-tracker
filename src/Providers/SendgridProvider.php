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
 * SendGrid webhook provider.
 *
 * @see https://docs.sendgrid.com/for-developers/tracking-events/event
 * @see https://docs.sendgrid.com/for-developers/tracking-events/getting-started-event-webhook-security-features
 */
class SendgridProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'sendgrid';
    }

    /**
     * Handle incoming webhook from SendGrid.
     *
     * SendGrid webhook events:
     * - processed: Message was accepted by SendGrid
     * - delivered: Message was delivered to recipient's mail server
     * - deferred: Message delivery was temporarily delayed
     * - bounce: Message bounced (hard or soft)
     * - dropped: Message was dropped (invalid, unsubscribed, etc.)
     * - open: Recipient opened the email
     * - click: Recipient clicked a link
     * - spamreport: Recipient marked as spam
     * - unsubscribe: Recipient unsubscribed
     * - group_unsubscribe: Recipient unsubscribed from a group
     * - group_resubscribe: Recipient resubscribed to a group
     *
     * Note: SendGrid sends an array of events in each webhook request.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        $this->logRawPayload($request);

        // Validate webhook signature
        if (! $this->validateSignature($request)) {
            $this->logError('Webhook signature validation failed');

            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $events = $request->all();

        // SendGrid sends an array of events
        if (! is_array($events) || empty($events)) {
            return response()->json(['success' => true, 'message' => 'No events to process']);
        }

        $processed = 0;
        $errors = 0;

        foreach ($events as $eventData) {
            if (! is_array($eventData)) {
                continue;
            }

            $eventType = $eventData['event'] ?? 'unknown';

            $this->logDebug("Processing SendGrid event: {$eventType}");

            $result = match ($eventType) {
                'bounce' => $this->handleBounce($eventData),
                'spamreport' => $this->handleComplaint($eventData),
                'delivered' => $this->handleDelivery($eventData),
                'dropped' => $this->handleDropped($eventData),
                'processed', 'deferred', 'open', 'click', 'unsubscribe', 'group_unsubscribe', 'group_resubscribe' => $this->handleGenericEvent($eventData, $eventType),
                default => response()->json(['success' => true]),
            };

            if ($result->getStatusCode() >= 400) {
                $errors++;
            } else {
                $processed++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Processed {$processed} events".($errors > 0 ? ", {$errors} errors" : ''),
        ]);
    }

    /**
     * Handle bounce event.
     */
    protected function handleBounce(array $eventData): JsonResponse
    {
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

            $email = $eventData['email'] ?? '';

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
     * Handle spam complaint event.
     */
    protected function handleComplaint(array $eventData): JsonResponse
    {
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

            $email = $eventData['email'] ?? '';

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
    protected function handleDelivery(array $eventData): JsonResponse
    {
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
     * Handle dropped event (treated similar to a bounce).
     */
    protected function handleDropped(array $eventData): JsonResponse
    {
        $messageId = $this->extractMessageId($eventData);

        if (! $messageId) {
            $this->logError('Dropped notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('bounce_tracking', true)
                ->firstOrFail();

            $email = $eventData['email'] ?? '';
            $reason = $eventData['reason'] ?? 'Unknown';

            $emailBounce = ModelResolver::get('email_bounce')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => 'Permanent', // Dropped emails are typically permanent failures
                'email' => $email,
                'bounced_at' => isset($eventData['timestamp']) ? Carbon::createFromTimestamp($eventData['timestamp']) : now(),
            ]);

            event(new EmailBounceEvent($emailBounce));

            $this->logDebug("Dropped event processed for: {$email} (reason: {$reason})");

            return response()->json(['success' => true, 'message' => 'Dropped event processed']);
        } catch (ModelNotFoundException) {
            $this->logDebug("Message ID ({$messageId}) not found or bounce tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process dropped event: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle generic events - log only for now.
     */
    protected function handleGenericEvent(array $eventData, string $eventType): JsonResponse
    {
        $messageId = $this->extractMessageId($eventData) ?? 'unknown';
        $email = $eventData['email'] ?? 'unknown';

        $this->logDebug("Received {$eventType} event for message: {$messageId} (email: {$email})");

        return response()->json(['success' => true, 'message' => "Event {$eventType} acknowledged"]);
    }

    /**
     * Extract message ID from SendGrid event data.
     *
     * SendGrid provides sg_message_id which includes the message ID and filter info.
     * Format: filter0000p1las1-00000000-0000-0000-0000-000000000000-000000
     */
    protected function extractMessageId(array $eventData): ?string
    {
        // Primary: sg_message_id (SendGrid's internal message ID)
        $sgMessageId = $eventData['sg_message_id'] ?? null;

        if ($sgMessageId) {
            // Extract the core message ID (remove filter prefix if present)
            // Format can be: filterXXXX-messageId or just messageId
            if (preg_match('/^filter\d+p\d+[a-z]+\d+-(.+)$/', $sgMessageId, $matches)) {
                return $matches[1];
            }

            return $sgMessageId;
        }

        // Fallback: smtp-id (the original Message-ID header)
        return $eventData['smtp-id'] ?? null;
    }

    /**
     * Determine bounce type from SendGrid event data.
     */
    protected function determineBounceType(array $eventData): string
    {
        $type = $eventData['type'] ?? '';
        $bounceClassification = $eventData['bounce_classification'] ?? '';

        // SendGrid bounce types: bounce (hard), blocked, expired
        if ($type === 'bounce' || $bounceClassification === 'Invalid Address') {
            return 'Permanent';
        }

        if ($type === 'blocked' || $type === 'expired' || $bounceClassification === 'Technical Failure') {
            return 'Transient';
        }

        // Check the reason field for additional context
        $reason = strtolower($eventData['reason'] ?? '');

        if (str_contains($reason, 'invalid') || str_contains($reason, 'does not exist') || str_contains($reason, 'unknown user')) {
            return 'Permanent';
        }

        if (str_contains($reason, 'temporarily') || str_contains($reason, 'try again') || str_contains($reason, 'rate limit')) {
            return 'Transient';
        }

        return 'Permanent';
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        return new EmailEventData(
            messageId: $this->extractMessageId($payload) ?? '',
            email: $payload['email'] ?? '',
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
     * SendGrid Event Webhook uses ECDSA signatures with the Twilio/SendGrid verification key.
     *
     * @see https://docs.sendgrid.com/for-developers/tracking-events/getting-started-event-webhook-security-features
     */
    public function validateSignature(Request $request): bool
    {
        $verificationKey = $this->getConfig('verification_key');

        if (! $verificationKey) {
            // If no key configured, skip validation (development mode)
            return true;
        }

        $signature = $request->header('X-Twilio-Email-Event-Webhook-Signature');
        $timestamp = $request->header('X-Twilio-Email-Event-Webhook-Timestamp');

        if (! $signature || ! $timestamp) {
            $this->logError('Missing signature or timestamp headers');

            return false;
        }

        // Verify timestamp is not too old (5 minutes)
        if (abs(time() - (int) $timestamp) > 300) {
            $this->logError('Webhook timestamp is too old');

            return false;
        }

        $payload = $request->getContent();
        $signedPayload = $timestamp.$payload;

        // SendGrid uses ECDSA P-256 signatures
        // The verification key is a public key in PEM format
        try {
            // Decode base64 signature
            $decodedSignature = base64_decode($signature);

            if ($decodedSignature === false) {
                $this->logError('Failed to decode signature');

                return false;
            }

            // Verify using OpenSSL
            $publicKey = openssl_pkey_get_public($verificationKey);

            if ($publicKey === false) {
                $this->logError('Invalid verification key');

                return false;
            }

            $result = openssl_verify(
                $signedPayload,
                $decodedSignature,
                $publicKey,
                OPENSSL_ALGO_SHA256,
            );

            return $result === 1;
        } catch (Exception $e) {
            $this->logError("Signature verification failed: {$e->getMessage()}");

            return false;
        }
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
