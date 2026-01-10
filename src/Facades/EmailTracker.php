<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Facades;

use Illuminate\Support\Facades\Facade;
use R0bdiabl0\EmailTracker\Contracts\BatchContract;
use R0bdiabl0\EmailTracker\Contracts\EmailProviderInterface;
use R0bdiabl0\EmailTracker\TrackedMailer;

/**
 * @method static TrackedMailer enableAllTracking()
 * @method static TrackedMailer enableOpenTracking()
 * @method static TrackedMailer enableLinkTracking()
 * @method static TrackedMailer enableBounceTracking()
 * @method static TrackedMailer enableComplaintTracking()
 * @method static TrackedMailer enableDeliveryTracking()
 * @method static TrackedMailer disableAllTracking()
 * @method static TrackedMailer disableOpenTracking()
 * @method static TrackedMailer disableLinkTracking()
 * @method static TrackedMailer disableBounceTracking()
 * @method static TrackedMailer disableComplaintTracking()
 * @method static TrackedMailer disableDeliveryTracking()
 * @method static TrackedMailer setBatch(string $batch)
 * @method static TrackedMailer setProvider(string $provider)
 * @method static TrackedMailer provider(string $provider)
 * @method static TrackedMailer useInitMessageCallback(\Closure $callback)
 * @method static BatchContract|null getBatch()
 * @method static int|null getBatchId()
 * @method static string getProvider()
 * @method static array trackingSettings()
 * @method static \Illuminate\Mail\SentMessage|null send(\Illuminate\Contracts\Mail\Mailable|string|array $view, array $data = [], \Closure|string|null $callback = null)
 * @method static \Illuminate\Mail\PendingMail to(mixed $users, ?string $name = null)
 * @method static \Illuminate\Mail\PendingMail cc(mixed $users, ?string $name = null)
 * @method static \Illuminate\Mail\PendingMail bcc(mixed $users, ?string $name = null)
 *
 * @see \R0bdiabl0\EmailTracker\TrackedMailer
 */
class EmailTracker extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'email-tracker';
    }

    /**
     * Get a provider handler by name.
     */
    public static function getProviderHandler(string $provider): ?EmailProviderInterface
    {
        return app("email-tracker.provider.{$provider}");
    }

    /**
     * Register a custom provider handler.
     */
    public static function registerProvider(string $name, string $handlerClass): void
    {
        app()->singleton("email-tracker.provider.{$name}", function () use ($handlerClass) {
            return new $handlerClass;
        });
    }

    /**
     * Get all enabled providers.
     */
    public static function getEnabledProviders(): array
    {
        $providers = [];

        foreach (config('email-tracker.providers', []) as $name => $settings) {
            if ($settings['enabled'] ?? false) {
                $providers[$name] = $settings;
            }
        }

        return $providers;
    }

    /**
     * Check if a provider is enabled.
     */
    public static function isProviderEnabled(string $provider): bool
    {
        return config("email-tracker.providers.{$provider}.enabled", false);
    }
}
