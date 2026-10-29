<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mozex\Campaigns\Http\Controllers\ConfirmationController;
use Mozex\Campaigns\Http\Controllers\PreviewController;
use Mozex\Campaigns\Http\Controllers\UnsubscribeController;
use Mozex\Campaigns\Http\Controllers\WebhookController;
use Mozex\Campaigns\Http\Controllers\WebVersionController;
use Mozex\Campaigns\Http\Middleware\PageHeaders;

/*
 * Outside the app's web middleware on purpose: a mail app's one-click
 * unsubscribe posts with no cookies or CSRF token, and these pages need no
 * session. The paths stay fixed, since delivered mail keeps pointing at them.
 */
Route::prefix('campaigns')
    ->name('campaigns.')
    ->middleware(PageHeaders::class)
    ->where(['token' => '[A-Za-z0-9]{16,128}'])
    ->group(function (): void {
        // Per visitor, through the alias, so an app that rebinds it decides
        // what a visitor is.
        Route::middleware('throttle:60,1')->group(function (): void {
            Route::get('u/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe');
            Route::get('c/{token}', [ConfirmationController::class, 'show'])->name('confirm');
            Route::get('v/{token}', WebVersionController::class)->name('web-version');
            Route::get('preview/{revision}', PreviewController::class)->name('preview')->whereUuid('revision');
        });

        // Per link: mail apps post one-click unsubscribes from a few shared
        // servers, which a per-visitor limit would turn away.
        Route::middleware('throttle:campaigns.links')->group(function (): void {
            Route::post('u/{token}', [UnsubscribeController::class, 'unsubscribe'])->name('unsubscribe.submit');
            Route::post('u/{token}/subscribe', [UnsubscribeController::class, 'resubscribe'])->name('resubscribe');
            Route::post('c/{token}', [ConfirmationController::class, 'confirm'])->name('confirm.submit');
        });

        // Not throttled: the signature is the gate, and a limit would turn
        // away the provider's bursts after a campaign's sends.
        Route::post('webhooks/mailgun', WebhookController::class)->name('webhooks.mailgun');
    });
