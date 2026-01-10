<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;

class EmailSentEvent
{
    use Dispatchable;
    use SerializesModels;

    public array $data;

    public SentEmailContract $sentEmail;

    public function __construct(SentEmailContract $sentEmail)
    {
        $this->sentEmail = $sentEmail;
        $this->data = $sentEmail->loadMissing('batch')->toArray();
    }
}
