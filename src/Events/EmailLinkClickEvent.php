<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use R0bdiabl0\EmailTracker\Contracts\EmailLinkContract;

class EmailLinkClickEvent
{
    use Dispatchable;
    use SerializesModels;

    public array $data;

    public EmailLinkContract $emailLink;

    public function __construct(EmailLinkContract $emailLink)
    {
        $this->emailLink = $emailLink;
        $this->data = $emailLink->loadMissing('sentEmail')->toArray();
    }
}
