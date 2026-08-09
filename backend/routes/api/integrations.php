<?php

declare(strict_types=1);

use App\Domains\Integrations\Http\Controllers\AdPlatformOAuthController;
use App\Domains\Integrations\Http\Controllers\IntegrationController;
use App\Domains\Integrations\Http\Controllers\IntegrationWebhookController;
use App\Domains\Integrations\Http\Controllers\PlatformOverviewController;
use App\Domains\Integrations\Http\Controllers\ProjectIntegrationController;
use App\Domains\Integrations\Http\Controllers\ProviderConnectionController;
use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
use Illuminate\Support\Facades\Route;

/*
 * The OAuth callback is PUBLIC, and it has to be (INTEG-OAUTH-001).
 *
 * A platform redirects a BROWSER here; no session cookie, bearer token or tenant header survives that
 * hop from an external origin. Everything the callback needs — tenant, user, workspace — therefore
 * comes out of the single-use `state` it claims, never out of its own query string.
 *
 * Throttled because it is unauthenticated: a state we did not issue is refused, but refusing it should
 * not be a free operation anybody can ask for ten thousand times a second.
 */
Route::get('oauth/ads/{provider}/callback', [AdPlatformOAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('oauth.ads.callback');

/*
 * WEBHOOK-001 — where providers push events.
 *
 * Public, and it has to be: a provider's server holds no session, no tenant header and no CSRF token.
 * It trusts nothing about its own request except an HMAC over the raw body, and an unverified
 * delivery is answered 401 with NOTHING recorded.
 *
 * The rate limit is generous rather than tight. These are legitimate machine callers that retry on
 * anything other than a 2xx, so throttling a busy account's real deliveries would make the provider
 * retry harder and eventually mark the subscription unhealthy. It exists to bound an unauthenticated
 * endpoint, not to pace the provider.
 *
 * `{kind}` keeps the advertising and commerce families on separate paths. They share the verification
 * machinery and nothing after it — one discovers ad accounts, the other stores — and a single
 * endpoint branching on the provider is how the two sets of rules drift into each other.
 */
Route::prefix('webhooks/{kind}')->name('webhooks.')->group(function (): void {
    Route::get('{provider}', [IntegrationWebhookController::class, 'verify'])
        ->middleware('throttle:60,1')->name('verify');
    Route::post('{provider}', [IntegrationWebhookController::class, 'receive'])
        ->middleware('throttle:600,1')->name('receive');
});

// Tenant-level connector status board + provider connections.
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    Route::prefix('integrations')->name('integrations.')->group(function (): void {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::get('{key}/health', [IntegrationController::class, 'health'])->name('health');
        Route::post('{key}/connect', [IntegrationController::class, 'connect'])->name('connect')
            ->middleware(EnsureWithinPlanLimit::class.':connections');
        Route::post('{key}/sync', [IntegrationController::class, 'sync'])->name('sync');

        // Start the authorisation for one of the six ad platforms. Returns a URL rather than a 302,
        // because the caller is an SPA doing `fetch` and a cross-origin redirect would be swallowed.
        Route::post('{provider}/oauth/start', [AdPlatformOAuthController::class, 'start'])->name('oauth.start');
    });

    Route::get('connections', [ProviderConnectionController::class, 'index'])->name('connections.index');
    Route::post('connections/{connection}/revoke', [ProviderConnectionController::class, 'revoke'])->name('connections.revoke');
});

// Per-project integrations (ResolveProject enforces project isolation).
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency', 'project'])
    ->prefix('projects/{project}/integrations')
    ->name('projects.integrations.')
    ->group(function (): void {
        Route::get('/', [ProjectIntegrationController::class, 'index'])->name('index');
        // PROJINT-001: the same project's integrations organised by the six real ad platforms.
        Route::get('platforms', [PlatformOverviewController::class, 'index'])->name('platforms');
        // Revoking frees the slot — `usage('connections')` skips revoked and disconnected rows.
        Route::post('connect', [ProjectIntegrationController::class, 'connect'])->name('connect')
            ->middleware(EnsureWithinPlanLimit::class.':connections');
        Route::post('bindings', [ProjectIntegrationController::class, 'bind'])->name('bind');
        Route::post('bindings/{binding}/sync', [ProjectIntegrationController::class, 'sync'])->name('sync');
        Route::delete('bindings/{binding}', [ProjectIntegrationController::class, 'detach'])->name('detach');
    });
