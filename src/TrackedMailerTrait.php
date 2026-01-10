<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Closure;
use Illuminate\Support\Carbon;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Events\EmailSentEvent;
use R0bdiabl0\EmailTracker\Exceptions\TooManyRecipientsException;
use Symfony\Component\Mime\Email;

trait TrackedMailerTrait
{
    protected ?Closure $initMessageCallback = null;

    protected string $provider = 'ses';

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
     */
    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
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
