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
 * Postmark webhook provider.
 *
 * @see https://postmarkapp.com/developer/webhooks/webhooks-overview
 * @see https://postmarkapp.com/developer/webhooks/bounce-webhook
 */
class PostmarkProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'postmark';
    }

    /**
     * Handle incoming webhook from Postmark.
     *
     * Postmark webhook events (RecordType):
     * - Delivery: Message was delivered to recipient's mail server
     * - Bounce: Message bounced (hard or soft)
     * - SpamComplaint: Recipient marked as spam
     * - Open: Recipient opened the email
     * - Click: Recipient clicked a link
     * - SubscriptionChange: Recipient unsubscribed or resubscribed
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        $this->logRawPayload($request);

        // Validate webhook
        if (! $this->validateSignature($request)) {
            $this->logError('Webhook signature validation failed');

            return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $eventType = $payload['RecordType'] ?? $event ?? 'unknown';

        $this->logDebug("Processing Postmark event: {$eventType}");

        return match ($eventType) {
            'Bounce' => $this->handleBounce($payload),
            'SpamComplaint' => $this->handleComplaint($payload),
            'Delivery' => $this->handleDelivery($payload),
            'Open', 'Click', 'SubscriptionChange' => $this->handleGenericEvent($payload, $eventType),
            default => response()->json(['success' => true, 'message' => 'Event type not tracked']),
        };
    }

    /**
     * Handle bounce event.
     */
    protected function handleBounce(array $payload): JsonResponse
    {
        $messageId = $payload['MessageID'] ?? null;

        if (! $messageId) {
            $this->logError('Bounce notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('bounce_tracking', true)
                ->firstOrFail();

            $email = $payload['Email'] ?? '';

            $emailBounce = ModelResolver::get('email_bounce')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $this->determineBounceType($payload),
                'email' => $email,
                'bounced_at' => isset($payload['BouncedAt']) ? Carbon::parse($payload['BouncedAt']) : now(),
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
    protected function handleComplaint(array $payload): JsonResponse
    {
        $messageId = $payload['MessageID'] ?? null;

        if (! $messageId) {
            $this->logError('Complaint notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('complaint_tracking', true)
                ->firstOrFail();

            $email = $payload['Email'] ?? '';

            $emailComplaint = ModelResolver::get('email_complaint')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => 'spam',
                'email' => $email,
                'complained_at' => isset($payload['BouncedAt']) ? Carbon::parse($payload['BouncedAt']) : now(),
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
        $messageId = $payload['MessageID'] ?? null;

        if (! $messageId) {
            $this->logError('Delivery notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->where('delivery_tracking', true)
                ->firstOrFail();

            $timestamp = isset($payload['DeliveredAt']) ? Carbon::parse($payload['DeliveredAt']) : now();
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
        $messageId = $payload['MessageID'] ?? 'unknown';
        $email = $payload['Recipient'] ?? $payload['Email'] ?? 'unknown';

        $this->logDebug("Received {$eventType} event for message: {$messageId} (email: {$email})");

        return response()->json(['success' => true, 'message' => "Event {$eventType} acknowledged"]);
    }

    /**
     * Determine bounce type from Postmark payload.
     *
     * Postmark TypeCode values:
     * - HardBounce (1): Permanent failure
     * - Transient (2): Temporary failure
     * - Unsubscribe (16): User unsubscribed
     * - Subscribe (32): User subscribed
     * - AutoResponder (64): Auto-reply
     * - AddressChange (128): Address changed
     * - DnsError (256): DNS lookup failed
     * - SpamNotification (512): Spam notification
     * - OpenRelayTest (1024): Open relay test
     * - Unknown (2048): Unknown type
     * - SoftBounce (4096): Soft bounce
     * - VirusNotification (8192): Virus notification
     * - ChallengeVerification (16384): Challenge verification
     * - BadEmailAddress (100000): Invalid email format
     * - SpamComplaint (100001): Spam complaint
     * - ManuallyDeactivated (100002): Manually deactivated
     * - Unconfirmed (100003): Email unconfirmed
     * - Blocked (100006): Blocked by Postmark
     * - SMTPApiError (100007): SMTP API error
     * - InboundError (100008): Inbound processing error
     * - DMARCPolicy (100009): DMARC policy rejection
     * - TemplateRenderingFailed (100010): Template rendering failed
     */
    protected function determineBounceType(array $payload): string
    {
        $typeCode = $payload['TypeCode'] ?? null;
        $type = $payload['Type'] ?? '';

        // Hard bounce type codes
        $permanentCodes = [1, 100000, 100001, 100002, 100006, 100009];

        // Soft bounce/transient type codes
        $transientCodes = [2, 256, 4096, 100007];

        if (in_array($typeCode, $permanentCodes, true)) {
            return 'Permanent';
        }

        if (in_array($typeCode, $transientCodes, true)) {
            return 'Transient';
        }

        // Fallback to string matching
        $typeLower = strtolower($type);

        if (str_contains($typeLower, 'hard') || str_contains($typeLower, 'bad')) {
            return 'Permanent';
        }

        if (str_contains($typeLower, 'soft') || str_contains($typeLower, 'transient')) {
            return 'Transient';
        }

        return 'Permanent';
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        $timestamp = null;

        if (isset($payload['DeliveredAt'])) {
            $timestamp = Carbon::parse($payload['DeliveredAt']);
        } elseif (isset($payload['BouncedAt'])) {
            $timestamp = Carbon::parse($payload['BouncedAt']);
        } elseif (isset($payload['ReceivedAt'])) {
            $timestamp = Carbon::parse($payload['ReceivedAt']);
        }

        return new EmailEventData(
            messageId: $payload['MessageID'] ?? '',
            email: $payload['Recipient'] ?? $payload['Email'] ?? '',
            provider: $this->getName(),
            eventType: $this->mapEventType($payload['RecordType'] ?? 'unknown'),
            timestamp: $timestamp,
            bounceType: $this->determineBounceType($payload),
            metadata: $payload,
        );
    }

    /**
     * Validate the webhook request signature.
     *
     * Postmark uses basic auth or a simple webhook token header.
     *
     * @see https://postmarkapp.com/developer/webhooks/webhooks-overview#webhook-security
     */
    public function validateSignature(Request $request): bool
    {
        $webhookToken = $this->getConfig('webhook_token');

        if (! $webhookToken) {
            // If no token configured, skip validation (development mode)
            return true;
        }

        // Option 1: Header-based token (custom implementation)
        $providedToken = $request->header('X-Postmark-Webhook-Token');

        if ($providedToken) {
            return hash_equals($webhookToken, $providedToken);
        }

        // Option 2: Basic auth (Postmark's built-in option)
        $authHeader = $request->header('Authorization');

        if ($authHeader && str_starts_with($authHeader, 'Basic ')) {
            $credentials = base64_decode(substr($authHeader, 6));

            if ($credentials !== false) {
                // Expected format: username:password or just :token
                $parts = explode(':', $credentials, 2);
                $password = $parts[1] ?? $parts[0];

                return hash_equals($webhookToken, $password);
            }
        }

        // No authentication provided - log warning but allow in development
        $this->logDebug('No webhook authentication provided');

        return false;
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
            'Click' => EmailEventType::Clicked,
            default => EmailEventType::Sent,
        };
    }
}
