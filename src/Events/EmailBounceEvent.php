<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Database\Eloquent\Model;
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

        // Load relationship and convert to array if it's an Eloquent model
        if ($emailBounce instanceof Model) {
            $this->data = $emailBounce->loadMissing('sentEmail')->toArray();
        } else {
            $this->data = ['id' => $emailBounce->getId()];
        }
    }
}
