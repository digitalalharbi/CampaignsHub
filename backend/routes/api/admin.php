<?php

declare(strict_types=1);

use App\Domains\Integrations\Review\ReviewChecklistController;
use App\Domains\Legal\Http\Controllers\LegalInboxController;
use App\Domains\Legal\Http\Controllers\PlatformSettingsController;
use App\Domains\Platform\Http\Controllers\OperationalStatusController;
use App\Domains\Platform\Http\Controllers\PlatformAccessController;
use App\Domains\Platform\Http\Controllers\PlatformBillingController;
use App\Domains\Platform\Http\Controllers\PlatformGrantController;
use App\Domains\Platform\Http\Controllers\PlatformOverviewController;
use App\Domains\Platform\Http\Controllers\PlatformPaymentSettingsController;
use App\Domains\Platform\Http\Controllers\PlatformProviderSettingsController;
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
         * GRANT-001 — one account's administrative exceptions.
         *
         * Nested under the tenant because a grant has no meaning apart from the account it was made
         * for, and because that shape makes "grant this to everybody" an endpoint nobody can call by
         * accident. `destroy` revokes; nothing here deletes.
         */
        Route::get('/tenants/{tenant}/grants', [PlatformGrantController::class, 'index'])->name('grants.index');
        Route::post('/tenants/{tenant}/grants', [PlatformGrantController::class, 'store'])
            ->middleware('throttle:30,1')->name('grants.store');
        Route::delete('/tenants/{tenant}/grants/{grant}', [PlatformGrantController::class, 'destroy'])
            ->middleware('throttle:30,1')->name('grants.destroy');

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

        /*
         * PROVCFG-001 — the ad and commerce providers, configured by the platform operator.
         *
         * Unlike the payment gateways above, these ARE written here. The difference is what a
         * compromise costs: a payment key redirects money, while a provider client secret lets an
         * attacker impersonate our OAuth app — bad, but recoverable by rotating at the provider, and
         * the alternative (a redeploy to add a customer's platform) makes the product unoperable.
         * Every write is audited by field name, and there is NO endpoint that reads a value back.
         *
         * `test` and `rotate` are throttled because both leave the building or change what leaves it.
         */
        Route::prefix('/settings/integrations/providers')->name('providers.')->group(function (): void {
            Route::get('/', [PlatformProviderSettingsController::class, 'index'])->name('index');
            Route::get('/{provider}', [PlatformProviderSettingsController::class, 'show'])->name('show');
            Route::put('/{provider}', [PlatformProviderSettingsController::class, 'update'])->name('update');
            Route::post('/{provider}/test', [PlatformProviderSettingsController::class, 'test'])
                ->middleware('throttle:10,1')->name('test');
            Route::post('/{provider}/rotate', [PlatformProviderSettingsController::class, 'rotate'])
                ->middleware('throttle:10,1')->name('rotate');
            Route::patch('/{provider}/status', [PlatformProviderSettingsController::class, 'status'])->name('status');
            Route::delete('/{provider}/credentials/{key}', [PlatformProviderSettingsController::class, 'forget'])
                ->name('credentials.forget');
        });

        // ADMIN-003 — read surfaces. The permission catalogue is code (PermissionSeeder), the
        // integration view counts what tenants have connected, and the status check is the SAME one
        // `/dev/status` runs rather than a second implementation.
        Route::get('/permissions', [PlatformAccessController::class, 'permissions'])->name('permissions.index');
        Route::get('/integrations', [PlatformAccessController::class, 'integrations'])->name('integrations.index');
        Route::get('/status', [DevStatusController::class, 'platform'])->name('status');

        /*
         * LEGAL-001 — who operates this platform, as printed on the public policies.
         *
         * Here rather than in a tenant's settings because there is one operator and one privacy
         * policy: a tenant administrator able to change the named data controller would be a
         * considerable problem. Unknown facts stay unset — nothing on this screen is defaulted to a
         * plausible-looking company, because a default here ends up on a published policy.
         */
        /*
         * LEGAL-002 — the operator's three queues.
         *
         * Separate endpoints rather than one merged inbox: an enquiry is sales, a ticket is support,
         * and a data request is a legal obligation with a deadline. One list sorted by date buries
         * the one with the deadline under the two without.
         */
        /*
         * REVIEW-001 — what each platform demands before it approves this application.
         *
         * One checklist per provider, never a shared one: Google approves the consent screen and the
         * developer token on separate tracks, Meta wants business verification, TikTok whitelists
         * advertiser ids in sandbox, Snapchat hangs accounts off an organisation, LinkedIn grants
         * products individually, X gates on a paid tier, and Salla and Zid each need a partner app.
         * A generic list would be wrong in both directions at once.
         */
        Route::get('/integrations/review', [ReviewChecklistController::class, 'index'])->name('integrations.review.index');
        Route::get('/integrations/review/{provider}', [ReviewChecklistController::class, 'show'])->name('integrations.review.show');
        Route::patch('/integrations/review/{provider}/{requirement}', [ReviewChecklistController::class, 'update'])
            ->name('integrations.review.update');

        Route::get('/legal/contact-messages', [LegalInboxController::class, 'contactMessages'])->name('legal.contact.index');
        Route::patch('/legal/contact-messages/{message}', [LegalInboxController::class, 'updateContactMessage'])->name('legal.contact.update');
        Route::get('/legal/support-tickets', [LegalInboxController::class, 'supportTickets'])->name('legal.tickets.index');
        Route::patch('/legal/support-tickets/{ticket}', [LegalInboxController::class, 'updateSupportTicket'])->name('legal.tickets.update');
        Route::get('/legal/data-requests', [LegalInboxController::class, 'dataRequests'])->name('legal.data-requests.index');
        Route::patch('/legal/data-requests/{dataRequest}', [LegalInboxController::class, 'updateDataRequest'])->name('legal.data-requests.update');

        Route::get('/settings/platform', [PlatformSettingsController::class, 'show'])->name('settings.platform.show');
        Route::put('/settings/platform', [PlatformSettingsController::class, 'update'])->name('settings.platform.update');

        /*
         * PROD-001 — is the deployment actually working, in the terms an operator acts on.
         *
         * Separate from `/status` above, which is the developer's snapshot (git branch, last
         * migration, the requirement board). This one answers only «are the background processes
         * alive, is anything queued, has anything failed» — the questions that decide whether to page
         * somebody — and is shaped to be scraped by a monitor rather than read by a person.
         *
         * Behind `platform` like everything else on this file: it names queue depths and failure
         * counts across every tenant, which is the platform operator's business and nobody else's.
         */
        Route::get('/operational-status', OperationalStatusController::class)->name('operational-status');

        // PORTAL-AUTH-001: the identities the backfill refused to guess at, and the safe way to
        // settle each one. No bulk resolve — see the controller for why.
        Route::get('/portal-conflicts', [PortalConflictController::class, 'index'])->name('portal-conflicts.index');
        Route::patch('/portal-conflicts/{conflict}', [PortalConflictController::class, 'resolve'])
            ->name('portal-conflicts.resolve');
        // Evidence only. There is deliberately no endpoint that performs the cutover.
        Route::get('/cutover-readiness', [PortalConflictController::class, 'readiness'])->name('cutover.readiness');

        Route::get('/audit', [PlatformTenantController::class, 'audit'])->name('audit.index');
    });
