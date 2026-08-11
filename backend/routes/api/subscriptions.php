<?php

declare(strict_types=1);

use App\Domains\Subscriptions\Http\Controllers\PublicPlanController;
use App\Domains\Subscriptions\Http\Controllers\SandboxCheckoutController;
use App\Domains\Subscriptions\Http\Controllers\SubscriptionController;
use App\Domains\Subscriptions\Http\Controllers\SubscriptionInvoiceController;
use App\Domains\Subscriptions\Http\Controllers\SubscriptionPaymentController;
use App\Domains\Subscriptions\Http\Controllers\SubscriptionPlanChangeController;
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

/*
 * Paying for a subscription (PAY-002).
 *
 * `webhook` carries NO auth and no throttle: a gateway has no session, and rate-limiting the channel
 * a payment confirmation arrives on is a way to lose one. The adapter's signature check is the whole
 * of the authentication, and an unverified body reaches nothing.
 *
 * There is deliberately no endpoint a browser can call to declare itself paid.
 */
Route::post('payments/webhook/{provider}', [SubscriptionPaymentController::class, 'webhook'])
    ->name('payments.webhook');

/*
 * The sandbox gateway's own pages (PAY-SANDBOX-001) — NEVER registered in production.
 *
 * Two guards, not one: the routes do not exist here, and `SandboxPaymentProvider::isConfigured()`
 * returns false in production whatever the environment says. Either alone would be enough; both
 * together mean a misconfigured deploy cannot reach a page that confirms payments.
 *
 * They confirm nothing themselves — `confirm` posts a SIGNED event to the webhook above, and that
 * webhook decides, exactly as it does for Moyasar and Stripe.
 */
if (! app()->environment('production')) {
    Route::get('payments/sandbox', [SandboxCheckoutController::class, 'show'])
        ->name('payments.sandbox.show');
    Route::post('payments/sandbox/confirm', [SandboxCheckoutController::class, 'confirm'])
        ->name('payments.sandbox.confirm');
}

Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('payments/providers', [SubscriptionPaymentController::class, 'providers'])->name('payments.providers');
    Route::post('auth/registration/{registration}/checkout', [SubscriptionPaymentController::class, 'checkout'])
        ->name('auth.registration.checkout');
});

/*
 * A shared invoice, publicly (SUBINV-001).
 *
 * The token IS the authorisation — 48 random characters, minted deliberately and revocable by
 * removing it. It exists so a customer can hand a document to an accountant who has no account here.
 */
Route::get('subscriptions/invoices/shared/{token}', [SubscriptionInvoiceController::class, 'shared'])
    ->middleware('throttle:30,1')->name('subscriptions.invoices.shared');

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    // CampaignsHub's own invoices to this customer — NOT the agency's invoices to its clients,
    // which live under /billing and answer to a different permission.
    Route::get('subscriptions/invoices', [SubscriptionInvoiceController::class, 'index'])
        ->name('subscriptions.invoices.index');
    Route::get('subscriptions/invoices/{invoice}', [SubscriptionInvoiceController::class, 'show'])
        ->name('subscriptions.invoices.show');
    Route::get('subscriptions/invoices/{invoice}/download', [SubscriptionInvoiceController::class, 'download'])
        ->name('subscriptions.invoices.download');
    Route::post('subscriptions/invoices/{invoice}/share', [SubscriptionInvoiceController::class, 'share'])
        ->name('subscriptions.invoices.share');
    Route::delete('subscriptions/invoices/{invoice}/share', [SubscriptionInvoiceController::class, 'revokeShare'])
        ->name('subscriptions.invoices.share.revoke');

    Route::get('subscriptions/plans', [SubscriptionController::class, 'plans'])->name('subscriptions.plans');
    Route::get('subscriptions/current', [SubscriptionController::class, 'current'])->name('subscriptions.current');
    Route::post('subscriptions/change', [SubscriptionController::class, 'change'])->name('subscriptions.change');

    /*
     * Taking the card off file (PAY-TOKEN-003).
     *
     * There is deliberately no endpoint that ADDS one. A card arrives one way only — the gateway
     * issues a token with a payment it settled, and the verified webhook files it — because an
     * endpoint that accepted a token from a browser would accept one from anybody who could reach it,
     * and the next thing that happens to a stored token is a charge.
     *
     * Removing is the customer's own decision and needs no gateway round trip, so it lives here.
     */
    Route::delete('subscriptions/payment-method', [SubscriptionController::class, 'detachPaymentMethod'])
        ->name('subscriptions.payment-method.detach');

    /*
     * Changing plan MID-TERM, with the money worked out (PAY-002).
     *
     * `quote` is separate from the commit on purpose: the numbers are the decision, and a customer
     * shown what a part-period upgrade actually costs before they agree to it is making one.
     */
    Route::post('subscriptions/plan-change/quote', [SubscriptionPlanChangeController::class, 'quote'])
        ->name('subscriptions.plan-change.quote');
    Route::post('subscriptions/plan-change', [SubscriptionPlanChangeController::class, 'store'])
        ->name('subscriptions.plan-change.store');
    Route::delete('subscriptions/plan-change', [SubscriptionPlanChangeController::class, 'destroy'])
        ->name('subscriptions.plan-change.destroy');
});
