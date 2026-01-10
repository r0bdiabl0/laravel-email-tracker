<?php

declare(strict_types=1);

namespace R0bdiabl0\EmailTracker\Controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use R0bdiabl0\EmailTracker\Contracts\EmailOpenContract;
use R0bdiabl0\EmailTracker\Events\EmailOpenEvent;
use R0bdiabl0\EmailTracker\ModelResolver;

class OpenController extends Controller
{
    /**
     * Track email open via beacon pixel.
     *
     * @throws Exception
     */
    public function track(string $beaconIdentifier): JsonResponse|Response
    {
        try {
            $emailOpen = ModelResolver::get('email_open')::whereBeaconIdentifier($beaconIdentifier)->firstOrFail();
            $emailOpen->opened_at = Carbon::now();
            $emailOpen->save();

            $this->sendEvent($emailOpen);

        } catch (ModelNotFoundException $e) {
            $logPrefix = config('email-tracker.log_prefix', 'EMAIL-TRACKER');
            Log::info("{$logPrefix} Could not find sent email with beacon identifier ({$beaconIdentifier}). Email open could not be recorded!");

            return response()->json([
                'success' => false,
                'errors' => ['Invalid Beacon'],
            ], 404);
        }

        // Return a 1x1 transparent GIF
        return $this->transparentPixelResponse();
    }

    /**
     * Fire the email open event.
     */
    protected function sendEvent(EmailOpenContract $emailOpen): void
    {
        event(new EmailOpenEvent($emailOpen));
    }

    /**
     * Return a transparent 1x1 pixel GIF response.
     */
    protected function transparentPixelResponse(): Response
    {
        // Transparent 1x1 GIF
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
