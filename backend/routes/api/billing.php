<?php

declare(strict_types=1);

use App\Domains\Billing\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

// Tenant-scoped billing: quotes, invoices, payments. Permission-gated inside the controller
// (billing.view / billing.manage); tenant isolation is enforced by the models' global scope.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('billing/quotes', [BillingController::class, 'quotes'])->name('billing.quotes.index');
    Route::post('billing/quotes', [BillingController::class, 'storeQuote'])->name('billing.quotes.store');
    Route::post('billing/quotes/{quote}/approve', [BillingController::class, 'approveQuote'])->name('billing.quotes.approve');

    Route::get('billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices.index');
    Route::post('billing/invoices/{invoice}/pay', [BillingController::class, 'startPayment'])->name('billing.invoices.pay');
});

// PUBLIC provider webhook sink — no auth, no tenant. The provider adapter verifies the signature; only a
// verified event can settle a payment. Kept outside the authed group on purpose.
Route::post('billing/webhook/{provider}', [BillingController::class, 'webhook'])->name('billing.webhook');
