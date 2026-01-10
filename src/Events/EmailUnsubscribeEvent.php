<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;

/**
 * Fired when a recipient clicks the one-click unsubscribe link.
 *
 * Listen to this event to handle your application's unsubscribe logic:
 * - Update user preferences
 * - Remove from mailing lists
 * - Log the unsubscribe action
 *
 * @example
 * ```php
 * class HandleUnsubscribe
 * {
 *     public function handle(EmailUnsubscribeEvent $event): void
 *     {
 *         User::where('email', $event->email)
 *             ->update(['marketing_emails' => false]);
 *     }
 * }
 * ```
 */
class EmailUnsubscribeEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * The email address that unsubscribed.
     */
    public string $email;

    /**
     * The sent email record (if found).
     */
    public ?SentEmailContract $sentEmail;

    /**
     * The message ID of the email that triggered the unsubscribe.
     */
    public ?string $messageId;

    /**
     * Additional context data.
     */
    public array $data;

    public function __construct(
        string $email,
        ?SentEmailContract $sentEmail = null,
        ?string $messageId = null,
        array $data = [],
    ) {
        $this->email = $email;
        $this->sentEmail = $sentEmail;
        $this->messageId = $messageId;
        $this->data = $data;
    }
}
