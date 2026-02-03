<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Enums\EmailEventType;
use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;
use R0bdiabl0\EmailTracker\Events\EmailComplaintEvent;
use R0bdiabl0\EmailTracker\Events\EmailDeliveryEvent;
use R0bdiabl0\EmailTracker\ModelResolver;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class SesProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'ses';
    }

    /**
     * Handle incoming webhook from AWS SNS.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response
    {
        try {
            // Convert to PSR-7 request for AWS SDK
            $psrRequest = app(\Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory::class)
                ->createRequest($request);
            $message = Message::fromPsrRequest($psrRequest);
        } catch (Exception $e) {
            // Fallback: try to parse from raw content
            try {
                $message = Message::fromRawPostData();
            } catch (Exception $e2) {
                $this->logError('Failed to parse SNS message: '.$e2->getMessage());

                return response()->json(['success' => false, 'error' => 'Invalid SNS message'], 400);
            }
        }

        $this->logRawPayload($request);

        // Validate SNS signature if enabled
        if ($this->shouldValidateSignature()) {
            try {
                $this->validateSignatureFromMessage($message);
            } catch (InvalidSnsMessageException $e) {
                $this->logError('SNS signature validation failed: '.$e->getMessage());

                return response()->json(['success' => false, 'error' => 'Invalid signature'], 403);
            }
        }

        // Handle subscription confirmation
        if ($this->isSubscriptionConfirmation($message)) {
            return $this->handleSubscriptionConfirmation($message);
        }

        // Handle topic validation
        if ($this->isTopicConfirmation($message)) {
            return response()->json(['success' => true, 'message' => 'Topic validated']);
        }

        // Parse and process the notification
        try {
            $messageContent = json_decode($message['Message'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logError('Failed to decode SNS message content: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => 'Invalid message content'], 400);
        }

        // Determine event type from route or message content
        $eventType = $event ?? $this->determineEventType($messageContent);

        return match ($eventType) {
            'bounce' => $this->handleBounce($messageContent),
            'complaint' => $this->handleComplaint($messageContent),
            'delivery' => $this->handleDelivery($messageContent),
            default => response()->json(['success' => false, 'error' => 'Unknown event type'], 400),
        };
    }

    /**
     * Parse webhook payload into standardized EmailEventData.
     */
    public function parsePayload(array $payload): EmailEventData
    {
        $sesEventType = $this->determineEventType($payload);
        $eventType = $this->mapToEmailEventType($sesEventType);

        $mail = $payload['mail'] ?? [];
        $messageId = $mail['messageId'] ?? '';
        $destination = $mail['destination'][0] ?? '';
        $timestamp = $mail['timestamp'] ?? null;

        return new EmailEventData(
            messageId: $messageId,
            email: $destination,
            provider: $this->getName(),
            eventType: $eventType,
            timestamp: $timestamp ? \Carbon\Carbon::parse($timestamp) : null,
            bounceType: $payload['bounce']['bounceType'] ?? null,
            complaintType: $payload['complaint']['complaintFeedbackType'] ?? null,
            metadata: $payload,
        );
    }

    /**
     * Map SES event type to EmailEventType enum.
     */
    protected function mapToEmailEventType(string $sesEventType): EmailEventType
    {
        return match ($sesEventType) {
            'bounce' => EmailEventType::Bounced,
            'complaint' => EmailEventType::Complained,
            'delivery' => EmailEventType::Delivered,
            default => EmailEventType::Sent,
        };
    }

    /**
     * Validate the webhook request signature.
     */
    public function validateSignature(Request $request): bool
    {
        if (! $this->shouldValidateSignature()) {
            return true;
        }

        try {
            $message = Message::fromRawPostData();
            $this->validateSignatureFromMessage($message);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate SNS message signature.
     *
     * @throws InvalidSnsMessageException
     */
    protected function validateSignatureFromMessage(Message $message): void
    {
        $validator = new MessageValidator;
        $validator->validate($message);
    }

    /**
     * Check if SNS validation is enabled.
     */
    protected function shouldValidateSignature(): bool
    {
        return $this->getConfig('sns_validator', true) === true;
    }

    /**
     * Check if message is a subscription confirmation.
     */
    protected function isSubscriptionConfirmation(Message $message): bool
    {
        return $message['Type'] === 'SubscriptionConfirmation';
    }

    /**
     * Check if message is a topic validation confirmation.
     */
    protected function isTopicConfirmation(Message $message): bool
    {
        return $message['Type'] === 'Notification'
            && Str::contains($message['Message'], 'Successfully validated SNS topic');
    }

    /**
     * Handle SNS subscription confirmation.
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     * @throws RuntimeException
     */
    protected function handleSubscriptionConfirmation(Message $message): JsonResponse
    {
        if (! isset($message['SubscribeURL'])) {
            throw new RuntimeException('Missing SubscribeURL in subscription confirmation');
        }

        $response = Http::get($message['SubscribeURL']);

        if ($response->status() !== 200) {
            throw new RuntimeException('Subscription confirmation request failed');
        }

        $this->logInfo("Subscribed to SNS topic: {$message['TopicArn']}");

        return response()->json(['success' => true, 'message' => 'Subscription confirmed']);
    }

    /**
     * Find a sent email by message ID with fallback to Message-ID header.
     *
     * SES assigns its own message ID which may differ from the Message-ID header
     * we set on the outgoing email. This method first tries to find by SES's
     * messageId, then falls back to checking the original Message-ID header.
     *
     * @param  string  $sesMessageId  The messageId from SES webhook
     * @param  array  $mail  The mail object from the webhook payload
     * @param  string  $trackingColumn  The tracking column to check (bounce_tracking, complaint_tracking, delivery_tracking)
     * @return \R0bdiabl0\EmailTracker\Contracts\SentEmailContract|null
     */
    protected function findSentEmail(string $sesMessageId, array $mail, string $trackingColumn)
    {
        // First try with SES's message ID
        $sentEmail = ModelResolver::get('sent_email')::query()
            ->where('message_id', $sesMessageId)
            ->where($trackingColumn, true)
            ->first();

        if ($sentEmail) {
            return $sentEmail;
        }

        // Fallback: try to find by the Message-ID header we set
        $headerMessageId = $this->extractMessageIdFromHeaders($mail);

        if ($headerMessageId) {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $headerMessageId)
                ->where($trackingColumn, true)
                ->first();

            if ($sentEmail) {
                $this->logDebug("Found sent email via Message-ID header fallback: {$headerMessageId}");

                return $sentEmail;
            }
        }

        return null;
    }

    /**
     * Extract the Message-ID header from SES webhook mail headers.
     *
     * SES includes original email headers in the webhook payload.
     * The headers array contains objects with 'name' and 'value' keys.
     */
    protected function extractMessageIdFromHeaders(array $mail): ?string
    {
        $headers = $mail['headers'] ?? [];

        foreach ($headers as $header) {
            if (isset($header['name'], $header['value'])) {
                if (strtolower($header['name']) === 'message-id') {
                    // Strip angle brackets if present: <uuid@domain> -> uuid@domain
                    return trim($header['value'], '<>');
                }
            }
        }

        return null;
    }

    /**
     * Determine event type from message content.
     */
    protected function determineEventType(array $content): string
    {
        if (isset($content['bounce'])) {
            return 'bounce';
        }
        if (isset($content['complaint'])) {
            return 'complaint';
        }
        if (isset($content['delivery'])) {
            return 'delivery';
        }

        return 'unknown';
    }

    /**
     * Handle bounce notification.
     */
    protected function handleBounce(array $content): JsonResponse
    {
        $mail = $content['mail'] ?? [];
        $bounce = $content['bounce'] ?? [];

        $messageId = $mail['messageId'] ?? null;

        if (! $messageId) {
            $this->logError('Bounce notification missing messageId');

            return response()->json(['success' => false, 'error' => 'Missing messageId'], 400);
        }

        try {
            $sentEmail = $this->findSentEmail($messageId, $mail, 'bounce_tracking');

            if (! $sentEmail) {
                $this->logDebug("Message ID ({$messageId}) not found or bounce tracking disabled. Skipping...");

                return response()->json(['success' => true, 'message' => 'Message not tracked']);
            }

            $bouncedRecipients = $bounce['bouncedRecipients'] ?? [];
            $email = $bouncedRecipients[0]['emailAddress'] ?? $mail['destination'][0] ?? '';

            $storeMetadata = config('email-tracker.store_metadata', false);

            $bounceData = [
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $bounce['bounceType'] ?? null,
                'email' => $email,
                'bounced_at' => isset($bounce['timestamp']) ? \Carbon\Carbon::parse($bounce['timestamp']) : now(),
            ];

            if ($storeMetadata) {
                $bounceData['metadata'] = $content;
            }

            $emailBounce = ModelResolver::get('email_bounce')::create($bounceData);

            // Always set metadata on model for event listeners
            $emailBounce->setAttribute('metadata', $content);

            event(new EmailBounceEvent($emailBounce));

            $this->logDebug("Bounce processed for: {$email}");

            return response()->json(['success' => true, 'message' => 'Bounce processed']);
        } catch (Exception $e) {
            $this->logError("Failed to process bounce: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle complaint notification.
     */
    protected function handleComplaint(array $content): JsonResponse
    {
        $mail = $content['mail'] ?? [];
        $complaint = $content['complaint'] ?? [];

        $messageId = $mail['messageId'] ?? null;

        if (! $messageId) {
            $this->logError('Complaint notification missing messageId');

            return response()->json(['success' => false, 'error' => 'Missing messageId'], 400);
        }

        try {
            $sentEmail = $this->findSentEmail($messageId, $mail, 'complaint_tracking');

            if (! $sentEmail) {
                $this->logDebug("Message ID ({$messageId}) not found or complaint tracking disabled. Skipping...");

                return response()->json(['success' => true, 'message' => 'Message not tracked']);
            }

            $complainedRecipients = $complaint['complainedRecipients'] ?? [];
            $email = $complainedRecipients[0]['emailAddress'] ?? $mail['destination'][0] ?? '';

            $storeMetadata = config('email-tracker.store_metadata', false);

            $complaintData = [
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $complaint['complaintFeedbackType'] ?? null,
                'email' => $email,
                'complained_at' => isset($complaint['timestamp']) ? \Carbon\Carbon::parse($complaint['timestamp']) : now(),
            ];

            if ($storeMetadata) {
                $complaintData['metadata'] = $content;
            }

            $emailComplaint = ModelResolver::get('email_complaint')::create($complaintData);

            // Always set metadata on model for event listeners
            $emailComplaint->setAttribute('metadata', $content);

            event(new EmailComplaintEvent($emailComplaint));

            $this->logDebug("Complaint processed for: {$email}");

            return response()->json(['success' => true, 'message' => 'Complaint processed']);
        } catch (Exception $e) {
            $this->logError("Failed to process complaint: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle delivery notification.
     */
    protected function handleDelivery(array $content): JsonResponse
    {
        $mail = $content['mail'] ?? [];
        $delivery = $content['delivery'] ?? [];

        $messageId = $mail['messageId'] ?? null;

        if (! $messageId) {
            $this->logError('Delivery notification missing messageId');

            return response()->json(['success' => false, 'error' => 'Missing messageId'], 400);
        }

        try {
            $sentEmail = $this->findSentEmail($messageId, $mail, 'delivery_tracking');

            if (! $sentEmail) {
                $this->logDebug("Message ID ({$messageId}) not found or delivery tracking disabled. Skipping...");

                return response()->json(['success' => true, 'message' => 'Message not tracked']);
            }

            $timestamp = $delivery['timestamp'] ?? null;
            $sentEmail->setDeliveredAt($timestamp ? \Carbon\Carbon::parse($timestamp) : now());

            event(new EmailDeliveryEvent($sentEmail));

            $this->logDebug("Delivery processed for message: {$messageId}");

            return response()->json(['success' => true, 'message' => 'Delivery processed']);
        } catch (Exception $e) {
            $this->logError("Failed to process delivery: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }
}
