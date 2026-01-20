<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Transports;

use Resend;
use Resend\Client;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony Mailer transport for Resend API.
 *
 * This transport allows sending emails through the Resend API while
 * maintaining compatibility with Laravel's mail system and TrackedMailer.
 *
 * Note: Requires the resend/resend-php package to be installed.
 *
 * @see https://resend.com/docs
 */
class ResendTransport extends AbstractTransport
{
    protected Client $client;

    public function __construct(string $apiKey)
    {
        parent::__construct();

        if (! class_exists(Client::class)) {
            throw new \RuntimeException(
                'The Resend SDK is not installed. Please run: composer require resend/resend-php'
            );
        }

        $this->client = Resend::client($apiKey);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $payload = $this->buildPayload($email);

            $this->client->emails->send($payload);
        } catch (\Exception $e) {
            throw new TransportException(
                sprintf('Unable to send email via Resend: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Build the Resend API payload from a Symfony Email.
     */
    protected function buildPayload(Email $email): array
    {
        $payload = [
            'from' => $this->formatAddress($email->getFrom()[0] ?? null),
            'to' => $this->formatAddresses($email->getTo()),
            'subject' => $email->getSubject() ?? '',
        ];

        // CC recipients
        if ($cc = $email->getCc()) {
            $payload['cc'] = $this->formatAddresses($cc);
        }

        // BCC recipients
        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = $this->formatAddresses($bcc);
        }

        // Reply-To
        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $this->formatAddresses($replyTo);
        }

        // HTML body
        if ($html = $email->getHtmlBody()) {
            $payload['html'] = $html;
        }

        // Text body
        if ($text = $email->getTextBody()) {
            $payload['text'] = $text;
        }

        // Custom headers (including List-Unsubscribe from TrackedMailer)
        $headers = $this->extractCustomHeaders($email);
        if (! empty($headers)) {
            $payload['headers'] = $headers;
        }

        // Attachments
        $attachments = $this->extractAttachments($email);
        if (! empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Format a single address for the Resend API.
     */
    protected function formatAddress(?Address $address): string
    {
        if (! $address) {
            return '';
        }

        if ($address->getName()) {
            return sprintf('%s <%s>', $address->getName(), $address->getAddress());
        }

        return $address->getAddress();
    }

    /**
     * Format multiple addresses for the Resend API.
     *
     * @param  Address[]  $addresses
     * @return string[]
     */
    protected function formatAddresses(array $addresses): array
    {
        return array_map(fn (Address $address) => $this->formatAddress($address), $addresses);
    }

    /**
     * Extract custom headers from the email.
     *
     * Includes the Message-ID header for webhook correlation - Resend
     * includes custom headers in webhook payloads, allowing us to match
     * webhook events back to our tracked emails.
     */
    protected function extractCustomHeaders(Email $email): array
    {
        $headers = [];

        // Include Message-ID for webhook correlation
        // TrackedMailer sets this, and Resend will return it in webhook payloads
        $messageIdHeader = $email->getHeaders()->get('Message-ID');
        if ($messageIdHeader) {
            $headers['X-Message-ID'] = $messageIdHeader->getBodyAsString();
        }

        // Standard headers we want to pass through
        $headerNames = [
            'List-Unsubscribe',
            'List-Unsubscribe-Post',
        ];

        foreach ($headerNames as $name) {
            $header = $email->getHeaders()->get($name);
            if ($header) {
                $headers[$name] = $header->getBodyAsString();
            }
        }

        // Pass through any X- prefixed headers
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            if (str_starts_with($name, 'X-') && ! isset($headers[$name])) {
                $headers[$name] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    /**
     * Extract attachments from the email.
     */
    protected function extractAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'filename' => $attachment->getFilename() ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
            ];
        }

        return $attachments;
    }

    public function __toString(): string
    {
        return 'resend';
    }
}
