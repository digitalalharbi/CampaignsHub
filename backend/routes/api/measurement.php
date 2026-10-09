<?php

declare(strict_types=1);

use App\Domains\Integrations\Http\Controllers\MeasurementOAuthController;
use App\Domains\Integrations\Http\Controllers\MeasurementPropertyController;
use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
use Illuminate\Support\Facades\Route;

/*
 * GA4-INTEGRATION-001 — connecting and reading a client's own Google Analytics 4 property.
 *
 * A separate file from `integrations.php` and `commerce.php` for the reason the third `ProviderKind`
 * exists: a measurement source is not an advertising platform and is not a store. Each family's
 * callback discovers a different thing, and one endpoint branching on the provider is how three sets
 * of rules drift into each other.
 *
 * The callback is PUBLIC and has to be: Google redirects a BROWSER here from its own origin, and no
 * session cookie, bearer token or tenant header survives that hop. Everything it needs — tenant, user,
 * workspace — comes out of the single-use `state` it claims.
 *
 * Throttled because it is unauthenticated. A state we did not issue is refused, but refusing it should
 * not be an operation anybody can ask for ten thousand times a second.
 */
Route::get('oauth/measurement/{provider}/callback', [MeasurementOAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('oauth.measurement.callback');

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    /*
     * Start the authorisation. Returns a URL rather than a 302, because the caller is an SPA doing
     * `fetch` and a cross-origin redirect would be swallowed.
     *
     * Under the connections plan limit for the same reason the other two flows are: all three end in
     * a `ProviderConnection`, and a limit one of them could walk around is not a limit.
     */
    Route::post('integrations/measurement/{provider}/oauth/start', [MeasurementOAuthController::class, 'start'])
        ->middleware(EnsureWithinPlanLimit::class.':connections')
        ->name('integrations.measurement.oauth.start');

    Route::prefix('measurement')->name('measurement.')->group(function (): void {
        /*
         * What this tenant's identities can SEE — the list somebody selects from.
         *
         * Read-only and deliberately plain. SELECTION is not here: a property becomes a project's
         * through the existing binding endpoint with `purpose = analytics`, which already enforces
         * the client-workspace fence, the one-active-binding rule and the quota. A second selection
         * path would be a second place for those three to be forgotten.
         */
        Route::get('properties', [MeasurementPropertyController::class, 'index'])->name('properties.index');

        /*
         * Pull a bound property's figures now. Throttled per tenant because the Data API meters in
         * TOKENS per property per day — a reader clicking repeatedly would spend a client's quota,
         * and exhaustion arrives as `RESOURCE_EXHAUSTED` for everybody on that property.
         */
        Route::post('properties/{account}/sync', [MeasurementPropertyController::class, 'sync'])
            ->middleware('throttle:12,1')->name('properties.sync');
    });
});
