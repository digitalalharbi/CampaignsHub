<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\Billing\Providers\SandboxPaymentProvider;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Services\ApplySubscriptionPaymentEvent;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\Frontend;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The sandbox gateway's own pages (PAY-SANDBOX-001).
 *
 * Two endpoints that stand in for the two pages a real gateway would serve: the one the customer is
 * sent to, and the one their confirmation posts to. They are deliberately NOT part of the product's
 * interface — they are served as plain HTML from the API, look nothing like CampaignsHub, and say
 * «SANDBOX» at the top, because a payment page that resembles the product is how somebody comes to
 * believe they paid.
 *
 * The important line in this file is the one that is absent: nothing here marks a payment paid.
 * Confirming builds a signed event and posts it through `ApplySubscriptionPaymentEvent`, which
 * verifies the signature, checks the amount, enforces idempotency and — if all of that holds — makes
 * the single `paymentConfirmed()` call the whole product depends on. The route to activation is the
 * same one a live gateway travels.
 *
 * Every route is registered only outside production; see `routes/api/subscriptions.php`.
 */
final class SandboxCheckoutController extends Controller
{
    public function __construct(
        private readonly SandboxPaymentProvider $sandbox,
        private readonly ApplySubscriptionPaymentEvent $events,
        private readonly TenantContext $tenants,
    ) {}

    /** GET /payments/sandbox?ref=… — the page the customer is sent to. */
    public function show(Request $request): Response
    {
        abort_unless($this->sandbox->isConfigured(), 404);

        $this->tenants->enterPlatformScope();

        $reference = (string) $request->query('ref', '');

        $payment = SubscriptionPayment::query()->where('idempotency_key', $reference)->first();

        if ($payment === null) {
            return response($this->page('No such charge.', null, $reference), 404)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        // Already settled. A gateway does not offer to take the same money twice, and neither does
        // this — the customer is told, rather than handed a button that would be a no-op.
        if ($payment->status === 'paid') {
            return response($this->page('This charge has already been paid.', null, $reference))
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        $amount = e((string) $payment->amount.' '.$payment->currency);

        return response($this->page("Authorise a payment of {$amount}.", e($reference), $reference))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * POST /payments/sandbox/{reference}/confirm — the customer pressed pay.
     *
     * What follows is a signed webhook, not a state change. The redirect afterwards goes to the
     * application's status page, which reads the application's ACTUAL state from the server — so if
     * the event were rejected for any reason, the customer would arrive to an unpaid application
     * rather than to a page that told them it had worked.
     */
    public function confirm(Request $request): RedirectResponse
    {
        abort_unless($this->sandbox->isConfigured(), 404);

        $this->tenants->enterPlatformScope();

        $reference = (string) $request->input('ref', $request->query('ref', ''));

        $payment = SubscriptionPayment::query()->where('idempotency_key', $reference)->firstOrFail();

        $body = json_encode([
            'id' => 'sbx_evt_'.Str::random(20),
            'type' => 'payment_paid',
            'data' => [
                'id' => 'sbx_pay_'.$payment->getKey(),
                'status' => 'paid',
                // Major units, matching what the charge was opened for. A sandbox that "paid" a
                // different amount would be testing the amount check rather than the journey — and
                // that check has its own test.
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->events->handle('sandbox', $body, [
            'x-sandbox-signature' => hash_hmac('sha256', $body, $this->sandbox->secret()),
        ]);

        $return = Frontend::origin().'/signup/status';

        if ($payment->registration_request_id !== null) {
            $return .= '?request='.$payment->registration_request_id;
        }

        return redirect()->away($return);
    }

    /** A page that could not be mistaken for this product, or for a bank. */
    private function page(string $message, ?string $payable, string $reference): string
    {
        $action = $payable === null ? '' : <<<HTML
            <form method="post" action="/api/v1/payments/sandbox/confirm">
                <input type="hidden" name="ref" value="{$payable}">
                <button type="submit" data-testid="sandbox-pay">Pay now (sandbox)</button>
            </form>
            HTML;

        $reference = e($reference);

        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><title>Sandbox gateway</title>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <style>
              body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:2rem;background:#111;color:#eee}
              main{max-width:32rem;margin:3rem auto;border:2px dashed #f5a623;padding:1.5rem;border-radius:12px}
              h1{font-size:.85rem;letter-spacing:.2em;color:#f5a623;margin:0 0 1rem}
              code{color:#9ad}
              button{margin-top:1.25rem;font:inherit;padding:.7rem 1.2rem;border:0;border-radius:8px;
                     background:#f5a623;color:#111;font-weight:700;cursor:pointer}
            </style></head>
            <body><main>
              <h1>SANDBOX — NOT A REAL GATEWAY</h1>
              <p>{$message}</p>
              <p>Reference <code>{$reference}</code></p>
              <p>No money moves. This page exists so the payment path can be walked on an installation
                 with no gateway credentials; it posts a signed event to the same webhook a live
                 gateway would, and the webhook decides what happens.</p>
              {$action}
            </main></body></html>
            HTML;
    }
}
