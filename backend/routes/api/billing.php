<?php

declare(strict_types=1);

use App\Domains\Billing\Http\Controllers\BillingController;
use App\Domains\Billing\Http\Controllers\FinanceCenterController;
use Illuminate\Support\Facades\Route;

// Tenant-scoped billing: quotes, invoices, payments. Permission-gated inside the controller
// (billing.view / billing.manage); tenant isolation is enforced by the models' global scope.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('billing/quotes', [BillingController::class, 'quotes'])->name('billing.quotes.index');
    Route::post('billing/quotes', [BillingController::class, 'storeQuote'])->name('billing.quotes.store');
    Route::post('billing/quotes/{quote}/approve', [BillingController::class, 'approveQuote'])->name('billing.quotes.approve');

    // FINANCE-001: the consolidated read model behind /app/finance, plus the payments ledger and the
    // receivables worklist — neither of which had an HTTP surface before.
    Route::get('billing/overview', [FinanceCenterController::class, 'overview'])->name('billing.overview');
    Route::get('billing/payments', [FinanceCenterController::class, 'payments'])->name('billing.payments.index');
    Route::get('billing/receivables', [FinanceCenterController::class, 'receivables'])->name('billing.receivables');

    Route::get('billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices.index');
    Route::post('billing/invoices/{invoice}/pay', [BillingController::class, 'startPayment'])->name('billing.invoices.pay');
});

// PUBLIC provider webhook sink — no auth, no tenant. The provider adapter verifies the signature; only a
// verified event can settle a payment. Kept outside the authed group on purpose.
Route::post('billing/webhook/{provider}', [BillingController::class, 'webhook'])->name('billing.webhook');
