<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Transports;

use Postal\Client;
use Postal\Send\Message as SendMessage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony Mailer transport for Postal API.
 *
 * This transport allows sending emails through the Postal API while
 * maintaining compatibility with Laravel's mail system and TrackedMailer.
 *
 * Note: Requires the postal/postal package to be installed.
 *
 * @see https://docs.postalserver.io/
 */
class PostalTransport extends AbstractTransport
{
    protected Client $client;

    public function __construct(string $serverUrl, string $apiKey)
    {
        parent::__construct();

        if (! class_exists(Client::class) || ! class_exists(SendMessage::class)) {
            throw new \RuntimeException(
                'The Postal SDK is not installed. Please run: composer require postal/postal'
            );
        }

        $this->client = new Client($serverUrl, $apiKey);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $postalMessage = $this->buildMessage($email);

            $response = $this->client->send->message($postalMessage);

            // Postal returns message details including the message_id
            // The TrackedMailer already sets its own message_id for tracking
        } catch (\Exception $e) {
            throw new TransportException(
                sprintf('Unable to send email via Postal: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Build the Postal message from a Symfony Email.
     */
    protected function buildMessage(Email $email): SendMessage
    {
        $message = new SendMessage($this->client);

        // From address
        $from = $email->getFrom()[0] ?? null;
        if ($from) {
            $message->from($this->formatAddress($from));
        }

        // To addresses
        foreach ($email->getTo() as $address) {
            $message->to($address->getAddress());
        }

        // CC addresses
        foreach ($email->getCc() as $address) {
            $message->cc($address->getAddress());
        }

        // BCC addresses
        foreach ($email->getBcc() as $address) {
            $message->bcc($address->getAddress());
        }

        // Subject
        if ($subject = $email->getSubject()) {
            $message->subject($subject);
        }

        // Reply-To
        $replyTo = $email->getReplyTo();
        if (! empty($replyTo)) {
            $message->replyTo($replyTo[0]->getAddress());
        }

        // HTML body
        if ($html = $email->getHtmlBody()) {
            $message->htmlBody($html);
        }

        // Plain text body
        if ($text = $email->getTextBody()) {
            $message->plainBody($text);
        }

        // Custom headers (including List-Unsubscribe from TrackedMailer)
        $this->addCustomHeaders($message, $email);

        // Attachments
        foreach ($email->getAttachments() as $attachment) {
            $message->attach(
                $attachment->getFilename() ?? 'attachment',
                $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                $attachment->getBody()
            );
        }

        return $message;
    }

    /**
     * Format a single address for display.
     */
    protected function formatAddress(Address $address): string
    {
        if ($address->getName()) {
            return sprintf('%s <%s>', $address->getName(), $address->getAddress());
        }

        return $address->getAddress();
    }

    /**
     * Add custom headers to the Postal message.
     *
     * Includes the Message-ID for webhook correlation - Postal includes
     * custom headers in webhook payloads.
     */
    protected function addCustomHeaders(SendMessage $message, Email $email): void
    {
        // Include Message-ID for webhook correlation
        $messageIdHeader = $email->getHeaders()->get('Message-ID');
        if ($messageIdHeader) {
            $message->header('X-Message-ID', $messageIdHeader->getBodyAsString());
        }

        // Standard headers we want to pass through
        $headerNames = [
            'List-Unsubscribe',
            'List-Unsubscribe-Post',
        ];

        foreach ($headerNames as $name) {
            $header = $email->getHeaders()->get($name);
            if ($header) {
                $message->header($name, $header->getBodyAsString());
            }
        }

        // Pass through any X- prefixed headers
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            if (str_starts_with($name, 'X-') && $name !== 'X-Message-ID') {
                $message->header($name, $header->getBodyAsString());
            }
        }
    }

    public function __toString(): string
    {
        return 'postal';
    }
}
