<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use R0bdiabl0\EmailTracker\Contracts\EmailComplaintContract;

class EmailComplaintEvent
{
    use Dispatchable;
    use SerializesModels;

    public array $data;

    public EmailComplaintContract $emailComplaint;

    public function __construct(EmailComplaintContract $emailComplaint)
    {
        $this->emailComplaint = $emailComplaint;
        $this->data = $emailComplaint->loadMissing('sentEmail')->toArray();
    }
}
