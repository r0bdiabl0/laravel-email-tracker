<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Exceptions;

class AddressSuppressedException extends EmailTrackerException
{
    protected string $email;

    protected string $reason;

    public function __construct(string $email, string $reason = 'bounced or complained')
    {
        $this->email = $email;
        $this->reason = $reason;

        parent::__construct("Email to '{$email}' was suppressed: {$reason}");
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
