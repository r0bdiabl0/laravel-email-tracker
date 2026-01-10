<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $validator = new MessageValidator();
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
     * @throws GuzzleException
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
            $sentEmail = ModelResolver::get('sent_email')::whereMessageId($messageId)
                ->whereBounceTracking(true)
                ->firstOrFail();

            $bouncedRecipients = $bounce['bouncedRecipients'] ?? [];
            $email = $bouncedRecipients[0]['emailAddress'] ?? $mail['destination'][0] ?? '';

            $emailBounce = ModelResolver::get('email_bounce')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $bounce['bounceType'] ?? null,
                'email' => $email,
                'bounced_at' => isset($bounce['timestamp']) ? \Carbon\Carbon::parse($bounce['timestamp']) : now(),
            ]);

            event(new EmailBounceEvent($emailBounce));

            $this->logDebug("Bounce processed for: {$email}");

            return response()->json(['success' => true, 'message' => 'Bounce processed']);

        } catch (ModelNotFoundException $e) {
            $this->logDebug("Message ID ({$messageId}) not found or bounce tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
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
            $sentEmail = ModelResolver::get('sent_email')::whereMessageId($messageId)
                ->whereComplaintTracking(true)
                ->firstOrFail();

            $complainedRecipients = $complaint['complainedRecipients'] ?? [];
            $email = $complainedRecipients[0]['emailAddress'] ?? $mail['destination'][0] ?? '';

            $emailComplaint = ModelResolver::get('email_complaint')::create([
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $complaint['complaintFeedbackType'] ?? null,
                'email' => $email,
                'complained_at' => isset($complaint['timestamp']) ? \Carbon\Carbon::parse($complaint['timestamp']) : now(),
            ]);

            event(new EmailComplaintEvent($emailComplaint));

            $this->logDebug("Complaint processed for: {$email}");

            return response()->json(['success' => true, 'message' => 'Complaint processed']);

        } catch (ModelNotFoundException $e) {
            $this->logDebug("Message ID ({$messageId}) not found or complaint tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
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
            $sentEmail = ModelResolver::get('sent_email')::whereMessageId($messageId)
                ->whereDeliveryTracking(true)
                ->firstOrFail();

            $timestamp = $delivery['timestamp'] ?? null;
            $sentEmail->setDeliveredAt($timestamp ? \Carbon\Carbon::parse($timestamp) : now());

            event(new EmailDeliveryEvent($sentEmail));

            $this->logDebug("Delivery processed for message: {$messageId}");

            return response()->json(['success' => true, 'message' => 'Delivery processed']);

        } catch (ModelNotFoundException $e) {
            $this->logDebug("Message ID ({$messageId}) not found or delivery tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process delivery: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }
}
