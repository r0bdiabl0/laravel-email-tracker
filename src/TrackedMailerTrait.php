<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Events\EmailSentEvent;
use R0bdiabl0\EmailTracker\Exceptions\TooManyRecipientsException;
use Symfony\Component\Mime\Email;

trait TrackedMailerTrait
{
    protected ?Closure $initMessageCallback = null;

    protected string $provider = 'ses';

    /**
     * Track whether the transport has been switched for this request.
     */
    protected bool $transportSwitched = false;

    /**
     * Initialize message tracking - creates database entry for the sent email.
     *
     * @throws TooManyRecipientsException
     */
    public function initMessage(Email $message): SentEmailContract
    {
        $this->checkNumberOfRecipients($message);

        $sentEmailModel = ModelResolver::get('sent_email')::create([
            'provider' => $this->provider,
            'message_id' => $message->generateMessageId(),
            'email' => $message->getTo()[0]->getAddress(),
            'batch_id' => $this->getBatchId(),
            'sent_at' => Carbon::now(),
            'delivery_tracking' => $this->deliveryTracking,
            'complaint_tracking' => $this->complaintTracking,
            'bounce_tracking' => $this->bounceTracking,
        ]);

        if (($callback = $this->initMessageCallback) !== null) {
            $callback($sentEmailModel);
        }

        return $sentEmailModel;
    }

    /**
     * Check message recipient count - tracking requires single recipient.
     *
     * @throws TooManyRecipientsException
     */
    protected function checkNumberOfRecipients(Email $message): void
    {
        if (count($message->getTo()) > 1) {
            throw new TooManyRecipientsException;
        }
    }

    /**
     * Set a callback to be called when initializing a message.
     */
    public function useInitMessageCallback(Closure $callback): self
    {
        $this->initMessageCallback = $callback;

        return $this;
    }

    /**
     * Set the provider for this mailer instance.
     *
     * This also switches the underlying Symfony transport to use the
     * provider-specific transport (e.g., Resend API, Postal API).
     */
    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        // Switch transport if a custom transport is registered for this provider
        $this->switchTransportForProvider($provider);

        return $this;
    }

    /**
     * Alias for setProvider() - fluent API convenience.
     */
    public function provider(string $provider): self
    {
        return $this->setProvider($provider);
    }

    /**
     * Switch the underlying transport to match the provider.
     *
     * If a mail transport is registered for the provider name (e.g., 'resend', 'postal'),
     * we switch to use that transport. For the default provider or when no dedicated
     * mailer exists, use the original/default transport.
     */
    protected function switchTransportForProvider(string $provider): void
    {
        // Store original transport on first switch
        if (! $this->transportSwitched && $this->originalTransport === null) {
            $this->originalTransport = $this->getSymfonyTransport();
        }

        // If switching back to default provider, restore original transport
        $defaultProvider = config('email-tracker.default_provider', 'ses');
        if ($provider === $defaultProvider && $this->originalTransport !== null) {
            $this->setSymfonyTransport($this->originalTransport);
            $this->transportSwitched = false;

            return;
        }

        // Check if Laravel has a mailer configured for this provider
        try {
            $mailer = Mail::mailer($provider);

            if ($mailer && method_exists($mailer, 'getSymfonyTransport')) {
                $transport = $mailer->getSymfonyTransport();
                $this->setSymfonyTransport($transport);
                $this->transportSwitched = true;

                return;
            }
        } catch (\Throwable $e) {
            // Mailer not found or failed to initialize - restore original transport.
            // This is critical: we must not keep whatever transport was set by a previous send.
            if ($this->originalTransport !== null) {
                $this->setSymfonyTransport($this->originalTransport);
                $this->transportSwitched = false;
            }

            if (config('email-tracker.debug')) {
                $logPrefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
                \Illuminate\Support\Facades\Log::debug(
                    "{$logPrefix} No dedicated mailer for provider '{$provider}', using default transport",
                    ['error' => $e->getMessage()]
                );
            }

            return;
        }

        // No mailer method available - restore original transport
        if ($this->originalTransport !== null) {
            $this->setSymfonyTransport($this->originalTransport);
            $this->transportSwitched = false;
        }
    }

    /**
     * Get the current provider name.
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Fire the email sent event.
     */
    protected function sendEvent(SentEmailContract $sentEmail): void
    {
        event(new EmailSentEvent($sentEmail));
    }
}
