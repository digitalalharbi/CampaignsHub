<?php

declare(strict_types=1);

use App\Domains\Commerce\Http\Controllers\SallaEntryController;
use App\Domains\Commerce\Http\Controllers\StoreController;
use App\Domains\Commerce\Http\Controllers\StoreOAuthController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
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

/*
 * SALLA-INSTALL-001 — a store installed from Salla's side. The preview is public (the claim code is
 * the secret, and it shows only the store's name); claiming needs a signed-in workspace.
 */
Route::get('commerce/store-installs/{claim}', [StoreOAuthController::class, 'installPreview'])
    ->middleware('throttle:30,1')->name('commerce.installs.preview');

/*
 * SALLA-ONBOARD-001 — the «Open app» door and the single-use sign-in link it ends in. Public, throttled, and
 * `enter` carries its own session middleware: the browser arrives as a top-level navigation from the callback
 * with no Origin header, so Sanctum's stateful detection would not start a session for it.
 */
Route::get('salla/open', [SallaEntryController::class, 'open'])->middleware('throttle:30,1')->name('salla.open');
Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'throttle:20,1'])
    ->get('salla/enter', [SallaEntryController::class, 'enter'])->name('salla.enter');

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    // Start the authorisation. Returns a URL rather than a 302, because the caller is an SPA doing
    // `fetch` and a cross-origin redirect would be swallowed.
    Route::post('integrations/commerce/{provider}/oauth/start', [StoreOAuthController::class, 'start'])
        ->name('integrations.commerce.oauth.start');

    Route::prefix('commerce')->name('commerce.')->group(function (): void {
        Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
        Route::post('store-installs/{claim}/claim', [StoreOAuthController::class, 'installClaim'])
            ->middleware('throttle:10,1')->name('installs.claim');
        Route::patch('stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::get('stores/{store}/runs', [StoreController::class, 'runs'])->name('stores.runs');
        Route::post('connections/{connection}/disconnect', [StoreController::class, 'disconnect'])->name('connections.disconnect');
        Route::post('stores/{store}/sync', [StoreController::class, 'sync'])
            ->middleware('throttle:12,1')->name('stores.sync');
    });
});
