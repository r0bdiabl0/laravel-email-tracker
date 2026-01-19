<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Traits;

use R0bdiabl0\EmailTracker\Facades\EmailTracker;
use R0bdiabl0\EmailTracker\Services\EmailValidator;

/**
 * Optional trait for Mailables that provides convenience methods for tracked sending.
 *
 * Usage:
 *   class WelcomeEmail extends Mailable
 *   {
 *       use TracksWithEmail;
 *   }
 *
 *   // Then use:
 *   WelcomeEmail::sendTracked('user@example.com', batch: 'welcome');
 *   WelcomeEmail::queueTracked(['user@example.com'], batch: 'welcome');
 */
trait TracksWithEmail
{
    /**
     * Send this mailable with tracking enabled.
     *
     * @param  string|array  $to  Email address(es) to send to
     * @param  string|null  $batch  Optional batch name for grouping
     * @param  string|null  $provider  Optional provider override
     *
     * @return bool True if sent successfully
     */
    public static function sendTracked(
        string|array $to,
        ?string $batch = null,
        ?string $provider = null,
    ): bool {
        $emails = is_array($to) ? $to : [$to];
        $emails = static::filterRecipients($emails);

        if (empty($emails)) {
            return false;
        }

        $sent = true;

        foreach ($emails as $email) {
            if (static::shouldBlockEmail($email)) {
                continue;
            }

            try {
                $mailer = EmailTracker::enableAllTracking();

                if ($batch || static::getDefaultBatch()) {
                    $mailer->setBatch($batch ?? static::getDefaultBatch());
                }

                if ($provider) {
                    $mailer->setProvider($provider);
                }

                $mailer->to($email)->send(new static(...static::getConstructorArgs()));
            } catch (\Exception $e) {
                report($e);
                $sent = false;
            }
        }

        return $sent;
    }

    /**
     * Queue this mailable with tracking enabled.
     *
     * @param  string|array  $to  Email address(es) to send to
     * @param  string|null  $batch  Optional batch name for grouping
     * @param  string  $queue  Queue name to use
     * @param  string|null  $provider  Optional provider override
     */
    public static function queueTracked(
        string|array $to,
        ?string $batch = null,
        string $queue = 'default',
        ?string $provider = null,
    ): void {
        $emails = is_array($to) ? $to : [$to];
        $emails = static::filterRecipients($emails);

        foreach ($emails as $email) {
            if (static::shouldBlockEmail($email)) {
                continue;
            }

            try {
                $mailer = EmailTracker::enableAllTracking();

                if ($batch || static::getDefaultBatch()) {
                    $mailer->setBatch($batch ?? static::getDefaultBatch());
                }

                if ($provider) {
                    $mailer->setProvider($provider);
                }

                $mailer->to($email)->queue(
                    (new static(...static::getConstructorArgs()))->onQueue($queue),
                );
            } catch (\Exception $e) {
                report($e);
            }
        }
    }

    /**
     * Check if an email address should be blocked from sending.
     * Override this method to add custom blocking logic.
     */
    protected static function shouldBlockEmail(string $email): bool
    {
        // Default implementation uses EmailValidator if suppression is enabled
        if (config('email-tracker.suppression.skip_bounced', false) ||
            config('email-tracker.suppression.skip_complained', false)) {
            return EmailValidator::shouldBlock($email);
        }

        return false;
    }

    /**
     * Filter recipient list before sending.
     * Override this method to add custom filtering logic.
     *
     * @param  array  $emails  List of email addresses
     *
     * @return array Filtered list
     */
    protected static function filterRecipients(array $emails): array
    {
        // Default: return all emails
        // Override to filter addresses
        return array_filter($emails, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));
    }

    /**
     * Get the default batch name for this mailable.
     * Override this method to set a default batch for all sends of this mailable.
     */
    protected static function getDefaultBatch(): ?string
    {
        return null;
    }

    /**
     * Get constructor arguments for creating a new instance.
     * Override this if your Mailable requires constructor arguments.
     *
     * @return array Arguments to pass to the constructor
     */
    protected static function getConstructorArgs(): array
    {
        return [];
    }
}
