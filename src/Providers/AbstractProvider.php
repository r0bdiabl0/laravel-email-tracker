<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use R0bdiabl0\EmailTracker\Contracts\EmailProviderInterface;

abstract class AbstractProvider implements EmailProviderInterface
{
    /**
     * Get the provider name identifier.
     */
    abstract public function getName(): string;

    /**
     * Check if this provider is enabled.
     */
    public function isEnabled(): bool
    {
        return config("email-tracker.providers.{$this->getName()}.enabled", false);
    }

    /**
     * Get provider-specific configuration.
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        $configPath = "email-tracker.providers.{$this->getName()}";

        if ($key === null) {
            return config($configPath, $default);
        }

        return config("{$configPath}.{$key}", $default);
    }

    /**
     * Log a debug message if debug mode is enabled.
     */
    protected function logDebug(string $message): void
    {
        if (config('email-tracker.debug', false)) {
            $prefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
            Log::debug("{$prefix} [{$this->getName()}]: {$message}");
        }
    }

    /**
     * Log an error message.
     */
    protected function logError(string $message): void
    {
        $prefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
        Log::error("{$prefix} [{$this->getName()}]: {$message}");
    }

    /**
     * Log an info message.
     */
    protected function logInfo(string $message): void
    {
        $prefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
        Log::info("{$prefix} [{$this->getName()}]: {$message}");
    }

    /**
     * Log the raw request payload if debug mode is enabled.
     */
    protected function logRawPayload(Request $request): void
    {
        if (config('email-tracker.debug', false)) {
            $this->logDebug('Raw payload: '.$request->getContent());
        }
    }
}
