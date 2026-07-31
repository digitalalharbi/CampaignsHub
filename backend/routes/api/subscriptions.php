<?php

declare(strict_types=1);

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
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    Route::get('subscriptions/plans', [SubscriptionController::class, 'plans'])->name('subscriptions.plans');
    Route::get('subscriptions/current', [SubscriptionController::class, 'current'])->name('subscriptions.current');
    Route::post('subscriptions/change', [SubscriptionController::class, 'change'])->name('subscriptions.change');
});
