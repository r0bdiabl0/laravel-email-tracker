<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use R0bdiabl0\EmailTracker\Contracts\EmailOpenContract;

class EmailOpenEvent
{
    use Dispatchable;
    use SerializesModels;

    public array $data;

    public EmailOpenContract $emailOpen;

    public function __construct(EmailOpenContract $emailOpen)
    {
        $this->emailOpen = $emailOpen;

        // Load relationship and convert to array if it's an Eloquent model
        if ($emailOpen instanceof Model) {
            $this->data = $emailOpen->loadMissing('sentEmail')->toArray();
        } else {
            $this->data = ['id' => $emailOpen->getId()];
        }
    }
}
