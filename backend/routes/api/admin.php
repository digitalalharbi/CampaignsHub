<?php

declare(strict_types=1);

use App\Domains\Platform\Http\Controllers\PlatformAccessController;
use App\Domains\Platform\Http\Controllers\PlatformBillingController;
use App\Domains\Platform\Http\Controllers\PlatformOverviewController;
use App\Domains\Platform\Http\Controllers\PlatformPaymentSettingsController;
use App\Domains\Platform\Http\Controllers\PlatformRegistrationController;
use App\Domains\Platform\Http\Controllers\PlatformTenantController;
use App\Domains\Platform\Http\Controllers\PortalConflictController;
use App\Http\Controllers\Dev\DevStatusController;
use Illuminate\Support\Facades\Route;

/*
| The platform owner's console (ADR 0002, ADMIN-001).
|
| Gated by `platform`, NOT by `portal:admin`. Every other portal is entered through a membership,
| which names a tenant — and the owner belongs to no tenant. Giving them a membership to reach this
| console would place them inside one of the workspaces they administer.
|
| Note the ABSENCE of the `tenant` middleware: these endpoints deliberately cross tenants, and each
| enters platform scope explicitly rather than inheriting whatever scope the request happened to have.
|
| What is NOT here is as deliberate as what is: no campaign, client or report data. The owner's job is
| tenants, plans, access and the audit trail. A console that made reading a customer's work effortless
| would see it happen without anyone deciding to.
*/
Route::middleware(['auth:sanctum', 'platform'])
    ->prefix('admin')->name('admin.')
    ->group(function (): void {
        Route::get('/overview', PlatformOverviewController::class)->name('overview');

        Route::get('/tenants', [PlatformTenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/{tenant}', [PlatformTenantController::class, 'show'])->name('tenants.show');
        Route::patch('/tenants/{tenant}/status', [PlatformTenantController::class, 'updateStatus'])
            ->name('tenants.status');

        /*
         * SIGNUP-003 — the registration review queue.
         *
         * The other end of the gated path. Approving clears the APPROVAL gate only; an application
         * that also owes money waits at `approved_awaiting_payment` for a confirmed payment, so
         * there is no action here that activates an account.
         */
        Route::get('/registrations', [PlatformRegistrationController::class, 'index'])->name('registrations.index');
        Route::get('/registrations/{registration}', [PlatformRegistrationController::class, 'show'])
            ->name('registrations.show');
        Route::patch('/registrations/{registration}', [PlatformRegistrationController::class, 'updateTerms'])
            ->name('registrations.terms');
        Route::post('/registrations/{registration}/approve', [PlatformRegistrationController::class, 'approve'])
            ->name('registrations.approve');
        Route::post('/registrations/{registration}/reject', [PlatformRegistrationController::class, 'reject'])
            ->name('registrations.reject');
        Route::post('/registrations/{registration}/request-info', [PlatformRegistrationController::class, 'requestInfo'])
            ->name('registrations.request-info');

        // ADMIN-002 — built on the existing Subscriptions and Billing engines, never a second one.
        Route::get('/plans', [PlatformBillingController::class, 'plans'])->name('plans.index');
        Route::patch('/plans/{plan}', [PlatformBillingController::class, 'updatePlan'])->name('plans.update');
        Route::get('/subscriptions', [PlatformBillingController::class, 'subscriptions'])->name('subscriptions.index');
        Route::get('/revenue', [PlatformBillingController::class, 'revenue'])->name('revenue.index');
        // PAY-005: the four streams, each with its owner, and no combined total.
        Route::get('/revenue-streams', [PlatformBillingController::class, 'revenueStreams'])->name('revenue.streams');

        /*
         * PAYSET-001 — the payment gateways.
         *
         * READ and TEST only. There is deliberately no endpoint that writes a gateway secret: a
         * console able to change one is a console whose compromise redirects every customer payment.
         * Keys live in the environment; this surface says what the environment supports and what is
         * missing, which is the question an operator actually has.
         */
        Route::prefix('/settings/integrations/payments')->name('payments.')->group(function (): void {
            Route::get('/', [PlatformPaymentSettingsController::class, 'index'])->name('index');
            Route::get('/{provider}/webhook', [PlatformPaymentSettingsController::class, 'webhook'])->name('webhook');
            Route::get('/{provider}/rotation', [PlatformPaymentSettingsController::class, 'rotation'])->name('rotation');
            // A real round trip to the gateway, rate limited because it leaves the building.
            Route::post('/{provider}/test', [PlatformPaymentSettingsController::class, 'test'])
                ->middleware('throttle:10,1')->name('test');
        });

        // ADMIN-003 — read surfaces. The permission catalogue is code (PermissionSeeder), the
        // integration view counts what tenants have connected, and the status check is the SAME one
        // `/dev/status` runs rather than a second implementation.
        Route::get('/permissions', [PlatformAccessController::class, 'permissions'])->name('permissions.index');
        Route::get('/integrations', [PlatformAccessController::class, 'integrations'])->name('integrations.index');
        Route::get('/status', [DevStatusController::class, 'platform'])->name('status');

        // PORTAL-AUTH-001: the identities the backfill refused to guess at, and the safe way to
        // settle each one. No bulk resolve — see the controller for why.
        Route::get('/portal-conflicts', [PortalConflictController::class, 'index'])->name('portal-conflicts.index');
        Route::patch('/portal-conflicts/{conflict}', [PortalConflictController::class, 'resolve'])
            ->name('portal-conflicts.resolve');
        // Evidence only. There is deliberately no endpoint that performs the cutover.
        Route::get('/cutover-readiness', [PortalConflictController::class, 'readiness'])->name('cutover.readiness');

        Route::get('/audit', [PlatformTenantController::class, 'audit'])->name('audit.index');
    });
