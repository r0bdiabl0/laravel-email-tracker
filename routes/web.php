<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use R0bdiabl0\EmailTracker\Controllers\LinkController;
use R0bdiabl0\EmailTracker\Controllers\OpenController;
use R0bdiabl0\EmailTracker\Controllers\WebhookController;

Route::prefix(config('email-tracker.routes.prefix', 'email-tracker'))
    ->middleware(config('email-tracker.routes.middleware', []))
    ->group(function () {
        // Dynamic webhook routes - provider identified from route parameter
        Route::post('/webhook/{provider}', [WebhookController::class, 'handle'])
            ->name('email-tracker.webhook');

        Route::post('/webhook/{provider}/{event}', [WebhookController::class, 'handle'])
            ->name('email-tracker.webhook.event');

        // Tracking endpoints (provider-agnostic)
        Route::get('/beacon/{beaconIdentifier}', [OpenController::class, 'track'])
            ->name('email-tracker.beacon');

        Route::get('/link/{linkIdentifier}', [LinkController::class, 'track'])
            ->name('email-tracker.link');
    });
