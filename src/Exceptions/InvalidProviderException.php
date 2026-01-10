<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Exceptions;

class InvalidProviderException extends EmailTrackerException
{
    public function __construct(string $provider)
    {
        parent::__construct("Unknown email provider: {$provider}");
    }
}
