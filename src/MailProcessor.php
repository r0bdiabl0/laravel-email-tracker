<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Exception;
use R0bdiabl0\EmailTracker\Contracts\SentEmailContract;
use Ramsey\Uuid\Uuid;
use voku\helper\HtmlDomParser;

class MailProcessor
{
    protected string $emailBody;

    protected SentEmailContract $sentEmail;

    public function __construct(SentEmailContract $sentEmail, string $emailBody)
    {
        $this->emailBody = $emailBody;
        $this->sentEmail = $sentEmail;
    }

    public function getEmailBody(): string
    {
        return $this->emailBody;
    }

    /**
     * Add open tracking beacon to email body.
     *
     * @throws Exception
     */
    public function openTracking(): self
    {
        $beaconIdentifier = Uuid::uuid4()->toString();
        $routePrefix = config('email-tracker.routes.prefix', 'email-tracker');
        $beaconUrl = config('app.url')."/{$routePrefix}/beacon/{$beaconIdentifier}";

        ModelResolver::get('email_open')::create([
            'sent_email_id' => $this->sentEmail->getId(),
            'beacon_identifier' => $beaconIdentifier,
        ]);

        $this->emailBody .= "<img src=\"{$beaconUrl}\" alt=\"\" style=\"width:1px;height:1px;\"/>";

        return $this;
    }

    /**
     * Replace links in email body with tracking URLs.
     *
     * @throws Exception
     */
    public function linkTracking(): self
    {
        $dom = HtmlDomParser::str_get_html($this->emailBody);

        foreach ($dom->findMulti('a') as $anchor) {
            $originalUrl = $anchor->getAttribute('href');

            // Only track HTTP/HTTPS links - skip tel:, mailto:, javascript:, etc.
            if ((string) $originalUrl !== '' && $this->isTrackableUrl($originalUrl)) {
                $anchor->setAttribute('href', $this->createTrackingLink($originalUrl));
            }
        }

        $this->emailBody = $dom->innerHtml;

        return $this;
    }

    /**
     * Check if a URL should be tracked.
     *
     * Only HTTP and HTTPS URLs are trackable. Other schemes like tel:, mailto:,
     * javascript:, data:, etc. are left unchanged in the email.
     */
    protected function isTrackableUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'])) {
            // Relative URLs or malformed URLs - don't track
            return false;
        }

        return in_array(strtolower($parsed['scheme']), ['http', 'https'], true);
    }

    /**
     * Create a tracking link for the original URL.
     *
     * @throws Exception
     */
    protected function createTrackingLink(string $originalUrl): string
    {
        $linkIdentifier = Uuid::uuid4()->toString();
        $routePrefix = config('email-tracker.routes.prefix', 'email-tracker');

        ModelResolver::get('email_link')::create([
            'sent_email_id' => $this->sentEmail->getId(),
            'link_identifier' => $linkIdentifier,
            'original_url' => $originalUrl,
        ]);

        return config('app.url')."/{$routePrefix}/link/{$linkIdentifier}";
    }
}
