<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use R0bdiabl0\EmailTracker\Contracts\EmailLinkContract;
use R0bdiabl0\EmailTracker\Events\EmailLinkClickEvent;
use R0bdiabl0\EmailTracker\ModelResolver;

class LinkController extends Controller
{
    /**
     * Track link click and redirect to original URL.
     *
     * @throws Exception
     */
    public function track(string $linkIdentifier): Redirector|RedirectResponse
    {
        try {
            $emailLink = ModelResolver::get('email_link')::whereLinkIdentifier($linkIdentifier)->firstOrFail();

            $emailLink->setClicked(true)->incrementClickCount();

            $this->sendEvent($emailLink);

            return redirect($emailLink->originalUrl());

        } catch (ModelNotFoundException $e) {
            $logPrefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
            Log::info("{$logPrefix} Could not find link ({$linkIdentifier}). Email link click count not incremented!");

            abort(404);
        }
    }

    /**
     * Fire the link click event.
     */
    protected function sendEvent(EmailLinkContract $emailLink): void
    {
        event(new EmailLinkClickEvent($emailLink));
    }
}
