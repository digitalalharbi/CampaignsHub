<?php

declare(strict_types=1);

use App\Domains\Subscriptions\Http\Controllers\PublicPlanController;
use App\Domains\Subscriptions\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Subscriptions / Plans / Usage (tenant-scoped)
|--------------------------------------------------------------------------
| Read the plan catalogue, the tenant's current subscription + honest usage/remaining, and (ops-gated) change
| plan. Permission-gated inside the controller (subscriptions.view / subscriptions.manage); tenant isolation is
| enforced by the service scoping every query to the resolved tenant.
|
| NOTE: This file is intentionally NOT wired into routes/api.php here — the orchestrator adds the
| `require __DIR__.'/api/subscriptions.php';` line. Middleware mirrors routes/api/billing.php
| (auth:sanctum, tenant) so the endpoints slot under the /api/v1 group unchanged.
*/
/*
 * The catalogue, publicly (PLAN-001).
 *
 * The pricing page and the sign-up form need it before anyone has an account, and both must quote
 * the same figures the checkout will charge. Rate limited because it is public and unauthenticated,
 * not because reading a price list is sensitive.
 */
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('plans', [PublicPlanController::class, 'index'])->name('plans.index');
    Route::get('plans/{code}/quote', [PublicPlanController::class, 'quote'])->name('plans.quote');
});

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    Route::get('subscriptions/plans', [SubscriptionController::class, 'plans'])->name('subscriptions.plans');
    Route::get('subscriptions/current', [SubscriptionController::class, 'current'])->name('subscriptions.current');
    Route::post('subscriptions/change', [SubscriptionController::class, 'change'])->name('subscriptions.change');
});
