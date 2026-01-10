<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use R0bdiabl0\EmailTracker\Controllers\LinkController;
use R0bdiabl0\EmailTracker\Controllers\OpenController;
use R0bdiabl0\EmailTracker\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Legacy Routes (Backwards Compatibility)
|--------------------------------------------------------------------------
|
| These routes maintain compatibility with juhasev/laravel-ses URL patterns.
| Enable them by setting EMAIL_TRACKER_LEGACY_ROUTES=true in your .env file.
|
*/

Route::prefix('/ses')->group(function () {
    // SNS notification endpoints (legacy paths)
    Route::post('/notification/bounce', [WebhookController::class, 'handle'])
        ->defaults('provider', 'ses')
        ->defaults('event', 'bounce')
        ->name('email-tracker.legacy.bounce');

    Route::post('/notification/delivery', [WebhookController::class, 'handle'])
        ->defaults('provider', 'ses')
        ->defaults('event', 'delivery')
        ->name('email-tracker.legacy.delivery');

    Route::post('/notification/complaint', [WebhookController::class, 'handle'])
        ->defaults('provider', 'ses')
        ->defaults('event', 'complaint')
        ->name('email-tracker.legacy.complaint');

    // Tracking endpoints (legacy paths)
    Route::get('/beacon/{beaconIdentifier}', [OpenController::class, 'track'])
        ->name('email-tracker.legacy.beacon');

    Route::get('/link/{linkIdentifier}', [LinkController::class, 'track'])
        ->name('email-tracker.legacy.link');
});
