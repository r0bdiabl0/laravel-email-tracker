<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Closure;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Contracts\TrackedMailerInterface;
use R0bdiabl0\EmailTracker\Exceptions\AddressSuppressedException;
use R0bdiabl0\EmailTracker\Exceptions\DailyQuotaExceededException;
use R0bdiabl0\EmailTracker\Exceptions\InvalidSenderAddressException;
use R0bdiabl0\EmailTracker\Exceptions\MaximumSendingRateExceededException;
use R0bdiabl0\EmailTracker\Exceptions\SendFailedException;
use R0bdiabl0\EmailTracker\Exceptions\TemporaryServiceFailureException;
use R0bdiabl0\EmailTracker\Services\EmailValidator;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Throwable;

class TrackedMailer extends Mailer implements TrackedMailerInterface
{
    use TrackedMailerTrait;
    use TrackingTrait;

    /**
     * The original transport (used when resetting provider).
     */
    protected ?TransportInterface $originalTransport = null;

    /**
     * Handle send exceptions and throw appropriate typed exceptions.
     *
     * @throws DailyQuotaExceededException
     * @throws InvalidSenderAddressException
     * @throws MaximumSendingRateExceededException
     * @throws SendFailedException
     * @throws TemporaryServiceFailureException
     */
    protected function throwException(Throwable $e, Email $symfonyMessage): void
    {
        $errorMessage = $this->parseErrorFromSymfonyTransportException($e->getMessage());
        $errorCode = $this->parseErrorCode($errorMessage);

        $logPrefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
        Log::error("{$logPrefix} Error: {$errorMessage}");

        if (Str::contains($errorMessage, '454 Throttling failure: Maximum sending rate exceeded')) {
            throw new MaximumSendingRateExceededException($errorMessage, $errorCode);
        }

        if (Str::contains($errorMessage, '454 Throttling failure: Daily message quota exceeded')) {
            throw new DailyQuotaExceededException($errorMessage, $errorCode);
        }

        if (Str::contains($errorMessage, '554 Message rejected: Email address is not verified')) {
            throw new InvalidSenderAddressException($errorMessage, $errorCode);
        }

        if (Str::contains($errorMessage, '451 Temporary service failure')) {
            throw new TemporaryServiceFailureException($errorMessage, $errorCode);
        }

        if (config('email-tracker.debug')) {
            Log::error("{$logPrefix} Symfony Message: ".print_r($symfonyMessage->getHeaders()->toArray(), true));
        }

        throw new SendFailedException($errorMessage, $errorCode);
    }

    protected function parseErrorFromSymfonyTransportException(string $message): string
    {
        $message = Str::after($message, ' with message "');

        return Str::beforeLast($message, '"');
    }

    protected function parseErrorCode(string $smtpError): int
    {
        return (int) Str::before($smtpError, ' Message');
    }

    /**
     * The last sent message for return value.
     */
    protected ?SentMessage $lastSentMessage = null;

    /**
     * Send the email with tracking.
     *
     * @param  MailableContract|string|array  $view
     * @param  Closure|string|null  $callback
     *
     * @throws AddressSuppressedException
     * @throws DailyQuotaExceededException
     * @throws InvalidSenderAddressException
     * @throws MaximumSendingRateExceededException
     * @throws SendFailedException
     * @throws TemporaryServiceFailureException
     */
    public function send($view, array $data = [], $callback = null): ?SentMessage
    {
        if ($view instanceof MailableContract) {
            return $this->sendMailable($view);
        }

        [$view, $plain, $raw] = $this->parseView($view);

        $data['message'] = $message = $this->createMessage();

        if (! is_null($callback)) {
            $callback($message);
        }

        $this->addContent($message, $view, $plain, $raw, $data);

        if (isset($this->to['address'])) {
            $this->setGlobalToAndRemoveCcAndBcc($message);
        }

        $symfonyMessage = $message->getSymfonyMessage();

        // Check suppression before sending
        $this->checkSuppression($symfonyMessage);

        if ($this->shouldSendMessage($symfonyMessage, $data)) {
            try {
                return $this->sendSymfonyMessage($symfonyMessage);
            } catch (Throwable $e) {
                $this->throwException($e, $symfonyMessage);
            }
        }

        return null;
    }

    /**
     * Send a mailable with suppression checking.
     *
     * @param  MailableContract  $mailable
     *
     * @throws AddressSuppressedException
     */
    protected function sendMailable(MailableContract $mailable): ?SentMessage
    {
        // Check suppression for all recipients before sending
        $this->checkMailableSuppression($mailable);

        return parent::sendMailable($mailable);
    }

    /**
     * Check if any recipients on a mailable are suppressed.
     *
     * @param  MailableContract  $mailable
     *
     * @throws AddressSuppressedException
     */
    protected function checkMailableSuppression(MailableContract $mailable): void
    {
        if (! EmailValidator::isSuppressionEnabled()) {
            return;
        }

        // Collect all recipients from the mailable
        $recipients = [];

        if (property_exists($mailable, 'to')) {
            $recipients = array_merge($recipients, (array) $mailable->to);
        }
        if (property_exists($mailable, 'cc')) {
            $recipients = array_merge($recipients, (array) $mailable->cc);
        }
        if (property_exists($mailable, 'bcc')) {
            $recipients = array_merge($recipients, (array) $mailable->bcc);
        }

        // Also check the global "to" set on the mailer
        if (isset($this->to['address'])) {
            $recipients[] = ['address' => $this->to['address']];
        }

        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? ($recipient['address'] ?? null) : $recipient;

            if (! $email) {
                continue;
            }

            $reason = EmailValidator::getBlockReason($email, $this->provider);
            if ($reason !== null) {
                throw new AddressSuppressedException($email, $reason);
            }
        }
    }

    /**
     * Check if any recipients are suppressed and throw exception if so.
     *
     * @param  Email  $message  The Symfony email message
     *
     * @throws AddressSuppressedException
     */
    protected function checkSuppression(Email $message): void
    {
        if (! EmailValidator::isSuppressionEnabled()) {
            return;
        }

        // Check all recipients (to, cc, bcc)
        $recipients = array_merge(
            $message->getTo(),
            $message->getCc(),
            $message->getBcc()
        );

        foreach ($recipients as $address) {
            $email = $address->getAddress();

            $reason = EmailValidator::getBlockReason($email, $this->provider);
            if ($reason !== null) {
                throw new AddressSuppressedException($email, $reason);
            }
        }
    }

    /**
     * Send a Symfony message with tracking.
     */
    protected function sendSymfonyMessage(Email $message): ?SentMessage
    {
        $sentEmail = $this->initMessage($message);

        $message->setHeaders($this->appendToHeaders($message->getHeaders(), $sentEmail));

        $message->html($this->setupTracking((string) $message->getHtmlBody(), $sentEmail));

        /** @var SentMessage|null $sentMessage */
        $sentMessage = parent::sendSymfonyMessage($message);

        $this->sendEvent($sentEmail);

        return $sentMessage;
    }

    /**
     * Append tracking headers to the message.
     */
    protected function appendToHeaders(Headers $headers, SentEmailContract $email): Headers
    {
        $headers->addIdHeader('Message-ID', $email->getMessageId());

        // Add SES configuration set header if using SES
        if ($this->provider === 'ses') {
            $configSetName = $this->getConfigurationSetName();
            if ($configSetName) {
                $headers->addTextHeader('X-SES-CONFIGURATION-SET', $configSetName);
            }
        }

        // Add List-Unsubscribe headers (RFC 8058) if enabled
        if ($this->shouldAddUnsubscribeHeaders()) {
            $this->addUnsubscribeHeaders($headers, $email);
        }

        return $headers;
    }

    /**
     * Check if unsubscribe headers should be added.
     */
    protected function shouldAddUnsubscribeHeaders(): bool
    {
        // Per-email flag takes precedence
        if ($this->unsubscribeHeaders) {
            return true;
        }

        // Fall back to global config
        return (bool) config('email-tracker.unsubscribe.enabled', false);
    }

    /**
     * Add RFC 8058 List-Unsubscribe headers.
     */
    protected function addUnsubscribeHeaders(Headers $headers, SentEmailContract $email): void
    {
        $unsubscribeUrl = $this->generateUnsubscribeUrl($email);

        // Build List-Unsubscribe header value
        $listUnsubscribe = "<{$unsubscribeUrl}>";

        // Optionally add mailto: fallback
        $mailto = config('email-tracker.unsubscribe.mailto');
        if ($mailto) {
            $listUnsubscribe = "<mailto:{$mailto}>, {$listUnsubscribe}";
        }

        $headers->addTextHeader('List-Unsubscribe', $listUnsubscribe);

        // RFC 8058 requires this header for one-click unsubscribe
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    /**
     * Generate a signed unsubscribe URL.
     */
    protected function generateUnsubscribeUrl(SentEmailContract $email): string
    {
        $routePrefix = config('email-tracker.routes.prefix', 'email-tracker');
        $expiration = (int) config('email-tracker.unsubscribe.signature_expiration', 0);

        $params = [
            'email' => $email->getEmail(),
            'message_id' => $email->getMessageId(),
        ];

        // Use Laravel's signed URL functionality
        if ($expiration > 0) {
            return URL::temporarySignedRoute(
                'email-tracker.unsubscribe',
                now()->addHours($expiration),
                $params,
            );
        }

        return URL::signedRoute('email-tracker.unsubscribe', $params);
    }

    /**
     * Get the AWS SES configuration set name.
     */
    protected function getConfigurationSetName(): ?string
    {
        $region = config('services.ses.region');

        if (! $region) {
            return null;
        }

        return App::environment().'-ses-'.$region;
    }
}
