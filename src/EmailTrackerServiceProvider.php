<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use R0bdiabl0\EmailTracker\Commands\InstallCommand;
use R0bdiabl0\EmailTracker\Commands\MigrateFromSesCommand;
use R0bdiabl0\EmailTracker\Commands\RollbackMigrationCommand;
use R0bdiabl0\EmailTracker\Transports\PostalTransport;
use R0bdiabl0\EmailTracker\Transports\ResendTransport;

class EmailTrackerServiceProvider extends ServiceProvider
{
    /**
     * Register services - for bindings and singletons only.
     */
    public function register(): void
    {
        // Merge config early so it's available during registration
        $this->mergeConfigFrom(__DIR__.'/../config/email-tracker.php', 'email-tracker');

        // Register the main TrackedMailer as a singleton
        $this->app->singleton(TrackedMailer::class, function (Application $app) {
            $transport = $app->make('mail.manager')->driver()->getSymfonyTransport();

            $mailer = new TrackedMailer(
                'email-tracker',
                $app->make('view'),
                $transport,
                $app->make('events'),
            );

            // Set the default provider from config
            $defaultProvider = $app->make('config')->get('email-tracker.default_provider', 'ses');
            $mailer->setProvider($defaultProvider);

            // Set the global from address if configured
            $fromAddress = $app->make('config')->get('mail.from.address');
            $fromName = $app->make('config')->get('mail.from.name');

            if ($fromAddress) {
                $mailer->alwaysFrom($fromAddress, $fromName);
            }

            return $mailer;
        });

        // Register facade accessor
        $this->app->alias(TrackedMailer::class, 'email-tracker');

        // Register provider handlers
        $this->registerProviders();
    }

    /**
     * Bootstrap services - for loading resources.
     */
    public function boot(): void
    {
        // Register custom mail transports
        $this->registerMailTransports();

        // Publish config with tag for selective publishing
        $this->publishes([
            __DIR__.'/../config/email-tracker.php' => config_path('email-tracker.php'),
        ], 'email-tracker-config');

        // Use publishesMigrations() for automatic timestamp updates (Laravel 11+)
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'email-tracker-migrations');

        // Load routes only if enabled in config
        if (config('email-tracker.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Register legacy routes if enabled
        if (config('email-tracker.legacy_routes.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/legacy.php');
        }

        // Register commands only when running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                MigrateFromSesCommand::class,
                RollbackMigrationCommand::class,
            ]);
        }
    }

    /**
     * Register custom mail transports for Resend and Postal.
     *
     * These transports allow emails to be sent through the respective APIs
     * while maintaining full compatibility with TrackedMailer for tracking,
     * suppression, and List-Unsubscribe headers.
     */
    protected function registerMailTransports(): void
    {
        // Register Resend transport
        Mail::extend('resend', function (array $config = []) {
            $apiKey = $config['key'] ?? config('services.resend.key');

            if (! $apiKey) {
                throw new \InvalidArgumentException('Resend API key not configured. Set RESEND_API_KEY in your .env file.');
            }

            return new ResendTransport($apiKey);
        });

        // Register Postal transport
        // Note: Use 'server_url' or 'host' instead of 'url' in mail config to avoid
        // Laravel's DSN URL parsing which would overwrite the transport driver.
        Mail::extend('postal', function (array $config = []) {
            $serverUrl = $config['server_url'] ?? $config['host'] ?? config('services.postal.url');
            $apiKey = $config['key'] ?? config('services.postal.key');

            if (! $serverUrl || ! $apiKey) {
                throw new \InvalidArgumentException('Postal server URL and API key required. Use server_url (not url) in mail config, or set POSTAL_URL and POSTAL_API_KEY in your .env file.');
            }

            return new PostalTransport($serverUrl, $apiKey);
        });
    }

    /**
     * Register provider handlers from config.
     *
     * Uses Laravel's IoC container for full dependency injection support.
     */
    protected function registerProviders(): void
    {
        foreach (config('email-tracker.providers', []) as $name => $settings) {
            if (($settings['enabled'] ?? false) && isset($settings['handler'])) {
                $this->app->singleton("email-tracker.provider.{$name}", function ($app) use ($settings) {
                    return $app->make($settings['handler']);
                });
            }
        }
    }
}
