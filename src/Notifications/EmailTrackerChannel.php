<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Notifications;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Notification;
use R0bdiabl0\EmailTracker\Facades\EmailTracker;
use R0bdiabl0\EmailTracker\Services\EmailValidator;

/**
 * Optional notification channel for tracked email sending.
 *
 * Usage:
 *   class WelcomeNotification extends Notification
 *   {
 *       public function via($notifiable): array
 *       {
 *           return [EmailTrackerChannel::class];
 *       }
 *
 *       public function toEmailTracker($notifiable): Mailable
 *       {
 *           return new WelcomeMail($notifiable);
 *       }
 *   }
 *
 * Or extend for custom behavior:
 *   class CustomEmailTrackerChannel extends EmailTrackerChannel
 *   {
 *       protected function shouldSend($notifiable, $notification): bool
 *       {
 *           // Add custom logic
 *           return parent::shouldSend($notifiable, $notification);
 *       }
 *   }
 */
class EmailTrackerChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification): void
    {
        $recipient = $this->getRecipient($notifiable);

        if (! $recipient) {
            return;
        }

        if (! $this->shouldSend($notifiable, $notification)) {
            return;
        }

        $this->beforeSend($notifiable, $notification);

        $mailable = $this->buildMessage($notifiable, $notification);

        if (! $mailable) {
            return;
        }

        try {
            $result = EmailTracker::enableAllTracking()
                ->to($recipient)
                ->send($mailable);

            $this->afterSend($notifiable, $notification, $result);
        } catch (\Exception $e) {
            $this->onSendFailure($notifiable, $notification, $e);

            throw $e;
        }
    }

    /**
     * Build the mail message from the notification.
     *
     * @param  mixed  $notifiable
     */
    protected function buildMessage($notifiable, Notification $notification): ?Mailable
    {
        // Check for toEmailTracker method first
        if (method_exists($notification, 'toEmailTracker')) {
            return $notification->toEmailTracker($notifiable);
        }

        // Fall back to toMail
        if (method_exists($notification, 'toMail')) {
            $mailMessage = $notification->toMail($notifiable);

            // If it's already a Mailable, return it
            if ($mailMessage instanceof Mailable) {
                return $mailMessage;
            }

            // Otherwise it's a MailMessage, which we can't use directly
            // The user should implement toEmailTracker() returning a Mailable
            return null;
        }

        return null;
    }

    /**
     * Get the recipient email address from the notifiable.
     *
     * @param  mixed  $notifiable
     */
    protected function getRecipient($notifiable): ?string
    {
        // Try routeNotificationFor first
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('email-tracker')
                ?? $notifiable->routeNotificationFor('mail');

            if ($route) {
                return is_array($route) ? $route[0] : $route;
            }
        }

        // Fall back to email property
        if (isset($notifiable->email)) {
            return $notifiable->email;
        }

        return null;
    }

    /**
     * Determine if the notification should be sent.
     * Override this method to add custom logic like bounce checking.
     *
     * @param  mixed  $notifiable
     */
    protected function shouldSend($notifiable, Notification $notification): bool
    {
        $recipient = $this->getRecipient($notifiable);

        if (! $recipient) {
            return false;
        }

        // Check for bounce/complaint if validation is enabled
        if (config('email-tracker.validation.skip_bounced', false) ||
            config('email-tracker.validation.skip_complained', false)) {
            if (EmailValidator::shouldBlock($recipient)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Called before sending the notification.
     * Override for pre-send logic like logging or rate limiting.
     *
     * @param  mixed  $notifiable
     */
    protected function beforeSend($notifiable, Notification $notification): void
    {
        // Override in subclass for custom behavior
    }

    /**
     * Called after successfully sending the notification.
     * Override for post-send logic like logging.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $result
     */
    protected function afterSend($notifiable, Notification $notification, $result): void
    {
        // Override in subclass for custom behavior
    }

    /**
     * Called when sending fails.
     * Override for error handling logic.
     *
     * @param  mixed  $notifiable
     */
    protected function onSendFailure($notifiable, Notification $notification, \Exception $e): void
    {
        // Override in subclass for custom error handling
    }
}
