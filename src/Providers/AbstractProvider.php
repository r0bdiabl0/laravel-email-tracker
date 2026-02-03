<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use R0bdiabl0\EmailTracker\Contracts\EmailProviderInterface;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use R0bdiabl0\EmailTracker\Events\EmailBounceEvent;
use R0bdiabl0\EmailTracker\Events\EmailComplaintEvent;
use R0bdiabl0\EmailTracker\Events\EmailDeliveryEvent;
use R0bdiabl0\EmailTracker\ModelResolver;

abstract class AbstractProvider implements EmailProviderInterface
{
    /**
     * Get the provider name identifier.
     */
    abstract public function getName(): string;

    /**
     * Check if this provider is enabled.
     */
    public function isEnabled(): bool
    {
        return config("email-tracker.providers.{$this->getName()}.enabled", false);
    }

    /**
     * Get provider-specific configuration.
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        $configPath = "email-tracker.providers.{$this->getName()}";

        if ($key === null) {
            return config($configPath, $default);
        }

        return config("{$configPath}.{$key}", $default);
    }

    /**
     * Log a debug message if debug mode is enabled.
     */
    protected function logDebug(string $message): void
    {
        if (config('email-tracker.debug', false)) {
            $prefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
            Log::debug("{$prefix} [{$this->getName()}]: {$message}");
        }
    }

    /**
     * Log an error message.
     */
    protected function logError(string $message): void
    {
        $prefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
        Log::error("{$prefix} [{$this->getName()}]: {$message}");
    }

    /**
     * Log an info message.
     */
    protected function logInfo(string $message): void
    {
        $prefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
        Log::info("{$prefix} [{$this->getName()}]: {$message}");
    }

    /**
     * Log the raw request payload if debug mode is enabled.
     */
    protected function logRawPayload(Request $request): void
    {
        if (config('email-tracker.debug', false)) {
            $this->logDebug('Raw payload: '.$request->getContent());
        }
    }

    /**
     * Process a bounce event - creates an EmailBounce record.
     *
     * Call this from your handleWebhook() method with parsed data.
     * This is a helper method for custom providers.
     *
     * @param  EmailEventData  $data  Parsed event data
     */
    protected function processBounceEvent(EmailEventData $data): JsonResponse
    {
        if (! $data->messageId) {
            $this->logError('Bounce notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $data->messageId)
                ->where('bounce_tracking', true)
                ->firstOrFail();

            $storeMetadata = config('email-tracker.store_metadata', false);

            $bounceData = [
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $data->bounceType ?? 'Permanent',
                'email' => $data->email,
                'bounced_at' => $data->timestamp ?? now(),
            ];

            if ($storeMetadata) {
                $bounceData['metadata'] = $data->metadata ?: null;
            }

            $emailBounce = ModelResolver::get('email_bounce')::create($bounceData);

            // Always set metadata on model for event listeners
            if ($data->metadata) {
                $emailBounce->setAttribute('metadata', $data->metadata);
            }

            event(new EmailBounceEvent($emailBounce));

            $this->logDebug("Bounce processed for: {$data->email}");

            return response()->json(['success' => true, 'message' => 'Bounce processed']);
        } catch (ModelNotFoundException) {
            $this->logDebug("Message ID ({$data->messageId}) not found or bounce tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process bounce: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Process a complaint event - creates an EmailComplaint record.
     *
     * Call this from your handleWebhook() method with parsed data.
     * This is a helper method for custom providers.
     *
     * @param  EmailEventData  $data  Parsed event data
     */
    protected function processComplaintEvent(EmailEventData $data): JsonResponse
    {
        if (! $data->messageId) {
            $this->logError('Complaint notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $data->messageId)
                ->where('complaint_tracking', true)
                ->firstOrFail();

            $storeMetadata = config('email-tracker.store_metadata', false);

            $complaintData = [
                'provider' => $this->getName(),
                'sent_email_id' => $sentEmail->id,
                'type' => $data->complaintType ?? 'spam',
                'email' => $data->email,
                'complained_at' => $data->timestamp ?? now(),
            ];

            if ($storeMetadata) {
                $complaintData['metadata'] = $data->metadata ?: null;
            }

            $emailComplaint = ModelResolver::get('email_complaint')::create($complaintData);

            // Always set metadata on model for event listeners
            if ($data->metadata) {
                $emailComplaint->setAttribute('metadata', $data->metadata);
            }

            event(new EmailComplaintEvent($emailComplaint));

            $this->logDebug("Complaint processed for: {$data->email}");

            return response()->json(['success' => true, 'message' => 'Complaint processed']);
        } catch (ModelNotFoundException) {
            $this->logDebug("Message ID ({$data->messageId}) not found or complaint tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process complaint: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }

    /**
     * Process a delivery event - updates the SentEmail.delivered_at timestamp.
     *
     * Call this from your handleWebhook() method with parsed data.
     * This is a helper method for custom providers.
     *
     * @param  EmailEventData  $data  Parsed event data
     */
    protected function processDeliveryEvent(EmailEventData $data): JsonResponse
    {
        if (! $data->messageId) {
            $this->logError('Delivery notification missing message ID');

            return response()->json(['success' => false, 'error' => 'Missing message ID'], 400);
        }

        try {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $data->messageId)
                ->where('delivery_tracking', true)
                ->firstOrFail();

            $sentEmail->setDeliveredAt($data->timestamp ?? now());

            event(new EmailDeliveryEvent($sentEmail));

            $this->logDebug("Delivery processed for message: {$data->messageId}");

            return response()->json(['success' => true, 'message' => 'Delivery processed']);
        } catch (ModelNotFoundException) {
            $this->logDebug("Message ID ({$data->messageId}) not found or delivery tracking disabled. Skipping...");

            return response()->json(['success' => true, 'message' => 'Message not tracked']);
        } catch (Exception $e) {
            $this->logError("Failed to process delivery: {$e->getMessage()}");

            return response()->json(['success' => false, 'error' => 'Processing failed'], 500);
        }
    }
}
