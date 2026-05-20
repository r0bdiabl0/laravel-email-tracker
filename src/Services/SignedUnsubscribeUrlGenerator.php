<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Services;

use Illuminate\Support\Facades\URL;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use R0bdiabl0\EmailTracker\Contracts\UnsubscribeUrlGenerator;

class SignedUnsubscribeUrlGenerator implements UnsubscribeUrlGenerator
{
    public function generate(SentEmailContract $email): string
    {
        $expiration = (int) config('email-tracker.unsubscribe.signature_expiration', 0);

        $params = [
            'email' => $email->getEmail(),
            'message_id' => $email->getMessageId(),
        ];

        if ($expiration > 0) {
            return URL::temporarySignedRoute(
                'email-tracker.unsubscribe',
                now()->addHours($expiration),
                $params,
            );
        }

        return URL::signedRoute('email-tracker.unsubscribe', $params);
    }
}
