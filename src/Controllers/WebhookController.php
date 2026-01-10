<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use R0bdiabl0\EmailTracker\Facades\EmailTracker;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    /**
     * Handle incoming webhook from any provider.
     */
    public function handle(Request $request, string $provider, ?string $event = null): Response
    {
        // Check if provider is enabled
        if (! EmailTracker::isProviderEnabled($provider)) {
            abort(404, "Provider not enabled: {$provider}");
        }

        // Get the provider handler
        $handler = EmailTracker::getProviderHandler($provider);

        if (! $handler) {
            abort(404, "Unknown provider: {$provider}");
        }

        // Let the provider handler process the webhook
        return $handler->handleWebhook($request, $event);
    }
}
