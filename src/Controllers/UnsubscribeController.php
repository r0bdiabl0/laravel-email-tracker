<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use R0bdiabl0\EmailTracker\Events\EmailUnsubscribeEvent;
use R0bdiabl0\EmailTracker\ModelResolver;

/**
 * Handles one-click unsubscribe requests (RFC 8058).
 *
 * This controller validates the signed URL and fires an EmailUnsubscribeEvent.
 * Your application handles the actual unsubscribe logic via event listeners.
 */
class UnsubscribeController extends Controller
{
    /**
     * Handle one-click unsubscribe request.
     *
     * RFC 8058 requires POST for one-click unsubscribe.
     * We also support GET for manual unsubscribe links in email body.
     */
    public function handle(Request $request): JsonResponse|RedirectResponse
    {
        $logPrefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');

        // Validate the signed URL
        if (! $this->validateSignature($request)) {
            Log::warning("{$logPrefix} Invalid unsubscribe signature attempted");

            return response()->json([
                'success' => false,
                'error' => 'Invalid or expired unsubscribe link',
            ], 403);
        }

        $email = $request->query('email');
        $messageId = $request->query('message_id');

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning("{$logPrefix} Invalid email in unsubscribe request");

            return response()->json([
                'success' => false,
                'error' => 'Invalid email address',
            ], 400);
        }

        // Find the sent email record if message_id is provided
        $sentEmail = null;
        if ($messageId) {
            $sentEmail = ModelResolver::get('sent_email')::query()
                ->where('message_id', $messageId)
                ->first();
        }

        // Fire the unsubscribe event - let the application handle the logic
        event(new EmailUnsubscribeEvent(
            email: $email,
            sentEmail: $sentEmail,
            messageId: $messageId,
            data: [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toIso8601String(),
            ],
        ));

        Log::info("{$logPrefix} Unsubscribe processed for: {$email}");

        // Return redirect or JSON based on config
        $redirectUrl = config('email-tracker.unsubscribe.redirect_url');

        if ($redirectUrl) {
            return redirect($redirectUrl);
        }

        return response()->json([
            'success' => true,
            'message' => 'Unsubscribe request processed',
        ]);
    }

    /**
     * Validate the signed URL.
     */
    protected function validateSignature(Request $request): bool
    {
        $expiration = (int) config('email-tracker.unsubscribe.signature_expiration', 0);

        if ($expiration > 0) {
            return URL::hasValidSignature($request);
        }

        // No expiration - just validate the signature without time check
        return URL::hasCorrectSignature($request);
    }
}
