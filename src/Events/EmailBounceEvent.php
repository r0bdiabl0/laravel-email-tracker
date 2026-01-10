<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use R0bdiabl0\EmailTracker\Contracts\EmailBounceContract;

class EmailBounceEvent
{
    use Dispatchable;
    use SerializesModels;

    public array $data;

    public EmailBounceContract $emailBounce;

    public function __construct(EmailBounceContract $emailBounce)
    {
        $this->emailBounce = $emailBounce;
        $this->data = $emailBounce->loadMissing('sentEmail')->toArray();
    }
}
