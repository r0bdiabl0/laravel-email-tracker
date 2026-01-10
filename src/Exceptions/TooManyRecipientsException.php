<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Exceptions;

class TooManyRecipientsException extends EmailTrackerException
{
    public function __construct(string $message = 'Cannot track emails with multiple recipients. Send one email per recipient.', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
