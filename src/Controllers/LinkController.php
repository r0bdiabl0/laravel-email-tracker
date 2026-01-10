<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Controllers;

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
     */
    public function track(string $linkIdentifier): Redirector|RedirectResponse
    {
        try {
            $emailLink = ModelResolver::get('email_link')::query()
                ->where('link_identifier', $linkIdentifier)
                ->firstOrFail();

            $emailLink->setClicked(true)->incrementClickCount();

            $this->sendEvent($emailLink);

            $url = $emailLink->originalUrl();

            // Validate URL is a valid HTTP/HTTPS URL to prevent open redirects
            if (! $this->isValidRedirectUrl($url)) {
                $logPrefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
                Log::warning("{$logPrefix} Invalid redirect URL detected: {$url}");
                abort(400);
            }

            return redirect($url);

        } catch (ModelNotFoundException) {
            $logPrefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
            Log::info("{$logPrefix} Could not find link ({$linkIdentifier}). Email link click count not incremented!");

            abort(404);
        }
    }

    /**
     * Validate that a URL is safe for redirection.
     *
     * Only allows HTTP and HTTPS URLs to prevent open redirect vulnerabilities
     * through javascript:, data:, or other potentially dangerous schemes.
     */
    protected function isValidRedirectUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'])) {
            return false;
        }

        // Only allow HTTP and HTTPS schemes
        return in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
    }

    /**
     * Fire the link click event.
     */
    protected function sendEvent(EmailLinkContract $emailLink): void
    {
        event(new EmailLinkClickEvent($emailLink));
    }
}
