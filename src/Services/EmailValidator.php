<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Services;

use R0bdiabl0\EmailTracker\ModelResolver;

class EmailValidator
{
    /**
     * Check if an email address should be blocked from sending.
     *
     * @param  string  $email  Email address to check
     * @param  string|null  $provider  Optional provider to check against
     */
    public static function shouldBlock(string $email, ?string $provider = null): bool
    {
        if (config('email-tracker.validation.skip_bounced', false)) {
            if (static::hasPermanentBounce($email, $provider)) {
                return true;
            }
        }

        if (config('email-tracker.validation.skip_complained', false)) {
            if (static::hasComplaint($email, $provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the bounce count for an email address.
     *
     * @param  string  $email  Email address
     * @param  string|null  $provider  Optional provider filter
     */
    public static function getBounceCount(string $email, ?string $provider = null): int
    {
        $query = ModelResolver::query('email_bounce')
            ->where('email', $email);

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->count();
    }

    /**
     * Get the complaint count for an email address.
     *
     * @param  string  $email  Email address
     * @param  string|null  $provider  Optional provider filter
     */
    public static function getComplaintCount(string $email, ?string $provider = null): int
    {
        $query = ModelResolver::query('email_complaint')
            ->where('email', $email);

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->count();
    }

    /**
     * Check if an email has a permanent (hard) bounce.
     *
     * @param  string  $email  Email address
     * @param  string|null  $provider  Optional provider filter
     */
    public static function hasPermanentBounce(string $email, ?string $provider = null): bool
    {
        $query = ModelResolver::query('email_bounce')
            ->where('email', $email)
            ->where('type', 'Permanent');

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->exists();
    }

    /**
     * Check if an email has any bounce (including soft bounces).
     *
     * @param  string  $email  Email address
     * @param  string|null  $provider  Optional provider filter
     */
    public static function hasBounce(string $email, ?string $provider = null): bool
    {
        $query = ModelResolver::query('email_bounce')
            ->where('email', $email);

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->exists();
    }

    /**
     * Check if an email has a complaint.
     *
     * @param  string  $email  Email address
     * @param  string|null  $provider  Optional provider filter
     */
    public static function hasComplaint(string $email, ?string $provider = null): bool
    {
        $query = ModelResolver::query('email_complaint')
            ->where('email', $email);

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->exists();
    }

    /**
     * Filter an array of emails, removing blocked addresses.
     *
     * @param  array  $emails  List of email addresses
     * @param  string|null  $provider  Optional provider filter
     *
     * @return array Filtered list with blocked addresses removed
     */
    public static function filterBlockedEmails(array $emails, ?string $provider = null): array
    {
        return array_filter($emails, fn ($email) => ! static::shouldBlock($email, $provider));
    }

    /**
     * Get validation summary for an email address.
     *
     * @param  string  $email  Email address
     * @param  string|null  $provider  Optional provider filter
     */
    public static function getValidationSummary(string $email, ?string $provider = null): array
    {
        return [
            'email' => $email,
            'provider' => $provider ?? 'all',
            'should_block' => static::shouldBlock($email, $provider),
            'bounce_count' => static::getBounceCount($email, $provider),
            'complaint_count' => static::getComplaintCount($email, $provider),
            'has_permanent_bounce' => static::hasPermanentBounce($email, $provider),
            'has_complaint' => static::hasComplaint($email, $provider),
        ];
    }
}
