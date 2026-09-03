<?php

declare(strict_types=1);

use App\Domains\Integrations\Http\Controllers\AccountInventoryController;
use App\Domains\Integrations\Http\Controllers\AdPlatformOAuthController;
use App\Domains\Integrations\Http\Controllers\ConnectionWizardController;
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

    /*
     * ORCH-100 — the connection wizard's reads: the hierarchy an authorisation discovered, a page of
     * its accounts, and what the plan has left. All three are tenant-scoped and none of them changes
     * anything: discovery is inventory until somebody confirms a selection.
     */
    Route::get('connections/resumable', [ConnectionWizardController::class, 'resumable'])->name('connections.resumable');
    Route::get('connections/{connection}/hierarchy', [ConnectionWizardController::class, 'hierarchy'])->name('connections.hierarchy');
    Route::get('connections/{connection}/accounts', [ConnectionWizardController::class, 'accounts'])->name('connections.accounts');
    Route::get('plan-usage', [ConnectionWizardController::class, 'planUsage'])->name('plan-usage');
    /*
     * RUNTIME-100 §5 — re-read the catalogue with the token we already hold.
     *
     * A WRITE, so it sits with the wizard's reads only in the file. Throttled because it calls out
     * to the provider: a button somebody can hold down should not become our rate-limit problem.
     */
    Route::post('connections/{connection}/refresh', [ConnectionWizardController::class, 'refresh'])
        ->middleware('throttle:10,1')->name('connections.refresh');

    /*
     * COMMAND-CENTER §§7–20 — the inventory that outlives the wizard.
     *
     * Tenant-scoped, not project-scoped, and that is the whole point: sources belong to the tenant
     * and are LENT to projects through a binding. A project-scoped route could not answer «what does
     * CampaignsHub have access to», which is the question this page exists for.
     *
     * There is no «enable» or «exclude» endpoint here, deliberately. An account is either linked to
     * a project or it is not, and that answer lives in `ProjectIntegrationBinding` — a second way to
     * say who owns an account is the exact defect this programme spent three PRs removing.
     */
    Route::get('accounts', [AccountInventoryController::class, 'index'])->name('accounts.index');
    Route::get('accounts/{account}/logs', [AccountInventoryController::class, 'logs'])->name('accounts.logs');
    // Throttled: it calls the provider, and a window somebody drags is a lot of requests.
    Route::post('accounts/{account}/backfill', [AccountInventoryController::class, 'backfill'])
        ->middleware('throttle:20,1')->name('accounts.backfill');
});

/*
 * Per-project integrations (ResolveProject enforces project isolation).
 *
 * TEAM-PROJECT-RBAC-001 — binding an ad account to a client is one of the most consequential acts in
 * the product: it decides whose money appears in whose report. It answered to the tenant role, which
 * every member of the agency holds.
 *
 * Writes take `integrations.manage`. The two READS take it as well, and that is deliberate: which ad
 * accounts a client is bound to, and under whose connection, is a fact about the agency's own
 * arrangements rather than about the client's performance — a reader who may see the figures is not
 * thereby entitled to see the plumbing behind them.
 */
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency', 'project'])
    ->prefix('projects/{project}/integrations')
    ->name('projects.integrations.')
    ->group(function (): void {
        Route::get('/', [ProjectIntegrationController::class, 'index'])->middleware('project.can:integrations.manage')->name('index');
        // PROJINT-001: the same project's integrations organised by the six real ad platforms.
        Route::get('platforms', [PlatformOverviewController::class, 'index'])->middleware('project.can:integrations.manage')->name('platforms');
        // Revoking frees the slot — `usage('connections')` skips revoked and disconnected rows.
        Route::post('connect', [ProjectIntegrationController::class, 'connect'])->middleware('project.can:integrations.manage')->name('connect')
            ->middleware(EnsureWithinPlanLimit::class.':connections');
        Route::post('bindings', [ProjectIntegrationController::class, 'bind'])->middleware('project.can:integrations.manage')->name('bind');
        // RUNTIME-100 §10 — a whole selection confirmed as one decision. Declared before `{binding}`
        // would matter if that route were a POST; it is not, but the order documents the intent.
        Route::post('bindings/batch', [ProjectIntegrationController::class, 'bindBatch'])->middleware('project.can:integrations.manage')->name('bind-batch');
        /*
         * INTEGRATION-DATASOURCE-WIZARD-001 §8 — «Manage accounts» saves the DESIRED SET and the
         * server derives the diff. A PUT because it is idempotent: the same set twice is the same
         * decision. It asks for no new authorisation — the token that discovered these accounts is
         * the token that binds them.
         */
        Route::put('selection', [ProjectIntegrationController::class, 'applySelection'])->middleware('project.can:integrations.manage')->name('selection');
        Route::post('bindings/{binding}/sync', [ProjectIntegrationController::class, 'sync'])->middleware('project.can:integrations.manage')->name('sync');
        Route::delete('bindings/{binding}', [ProjectIntegrationController::class, 'detach'])->middleware('project.can:integrations.manage')->name('detach');
    });
