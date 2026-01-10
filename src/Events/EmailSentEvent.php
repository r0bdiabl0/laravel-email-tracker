<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Database\Eloquent\Model;
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

        // Load relationship and convert to array if it's an Eloquent model
        if ($sentEmail instanceof Model) {
            $this->data = $sentEmail->loadMissing('batch')->toArray();
        } else {
            $this->data = [
                'id' => $sentEmail->getId(),
                'message_id' => $sentEmail->getMessageId(),
            ];
        }
    }
}
