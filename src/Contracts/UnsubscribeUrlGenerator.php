<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Contracts;

interface UnsubscribeUrlGenerator
{
    /**
     * Generate the unsubscribe URL for a sent email.
     */
    public function generate(SentEmailContract $email): string;
}
