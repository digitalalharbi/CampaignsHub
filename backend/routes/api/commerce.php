<?php

declare(strict_types=1);

use App\Domains\Commerce\Http\Controllers\StoreController;
use App\Domains\Commerce\Http\Controllers\StoreOAuthController;
use Illuminate\Support\Facades\Route;

/*
 * COMMERCE-001 — connecting and reading a merchant's own Salla or Zid store.
 *
 * The callback is PUBLIC and has to be: the store platform redirects a BROWSER here from its own
 * origin, and no session cookie, bearer token or tenant header survives that hop. Everything it needs
 * — tenant, user, workspace — comes out of the single-use `state` it claims.
 *
 * Throttled because it is unauthenticated. A state we did not issue is refused, but refusing it should
 * not be an operation anybody can ask for ten thousand times a second.
 */
Route::get('oauth/commerce/{provider}/callback', [StoreOAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('oauth.commerce.callback');

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    // Start the authorisation. Returns a URL rather than a 302, because the caller is an SPA doing
    // `fetch` and a cross-origin redirect would be swallowed.
    Route::post('integrations/commerce/{provider}/oauth/start', [StoreOAuthController::class, 'start'])
        ->name('integrations.commerce.oauth.start');

    Route::prefix('commerce')->name('commerce.')->group(function (): void {
        Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
        Route::post('stores/{store}/sync', [StoreController::class, 'sync'])
            ->middleware('throttle:12,1')->name('stores.sync');
    });
});
