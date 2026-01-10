<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Contracts;

use Illuminate\Http\Request;
use R0bdiabl0\EmailTracker\DataTransferObjects\EmailEventData;
use Symfony\Component\HttpFoundation\Response;

interface EmailProviderInterface
{
    /**
     * Get the provider name identifier.
     */
    public function getName(): string;

    /**
     * Handle an incoming webhook request from this provider.
     */
    public function handleWebhook(Request $request, ?string $event = null): Response;

    /**
     * Parse the webhook payload into a standardized EmailEventData object.
     */
    public function parsePayload(array $payload): EmailEventData;

    /**
     * Validate the webhook request signature/authenticity.
     */
    public function validateSignature(Request $request): bool;

    /**
     * Check if this provider is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Get provider-specific configuration.
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed;
}
