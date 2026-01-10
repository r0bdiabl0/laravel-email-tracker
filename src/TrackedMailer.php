<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Closure;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Contracts\TrackedMailerInterface;
use R0bdiabl0\EmailTracker\Exceptions\DailyQuotaExceededException;
use R0bdiabl0\EmailTracker\Exceptions\InvalidSenderAddressException;
use R0bdiabl0\EmailTracker\Exceptions\MaximumSendingRateExceededException;
use R0bdiabl0\EmailTracker\Exceptions\SendFailedException;
use R0bdiabl0\EmailTracker\Exceptions\TemporaryServiceFailureException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Throwable;

class TrackedMailer extends Mailer implements TrackedMailerInterface
{
    use TrackedMailerTrait;
    use TrackingTrait;

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
     * Send a Symfony message with tracking.
     *
     * @return SentMessage|null
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

        return $headers;
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
