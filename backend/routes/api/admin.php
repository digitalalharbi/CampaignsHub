<?php

declare(strict_types=1);

use App\Domains\Platform\Http\Controllers\PlatformBillingController;
use App\Domains\Platform\Http\Controllers\PlatformOverviewController;
use App\Domains\Platform\Http\Controllers\PlatformTenantController;
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

        // ADMIN-002 — built on the existing Subscriptions and Billing engines, never a second one.
        Route::get('/plans', [PlatformBillingController::class, 'plans'])->name('plans.index');
        Route::patch('/plans/{plan}', [PlatformBillingController::class, 'updatePlan'])->name('plans.update');
        Route::get('/subscriptions', [PlatformBillingController::class, 'subscriptions'])->name('subscriptions.index');
        Route::get('/revenue', [PlatformBillingController::class, 'revenue'])->name('revenue.index');

        Route::get('/audit', [PlatformTenantController::class, 'audit'])->name('audit.index');
    });
