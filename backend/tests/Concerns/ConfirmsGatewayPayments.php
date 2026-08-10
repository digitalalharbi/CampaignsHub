<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Stand in for Moyasar's own `GET /v1/payments/{id}` (PAY-CONFIRM-001).
 *
 * ## Why every Moyasar settlement in the suite needs this
 *
 * A Moyasar webhook authenticates with a shared secret carried INSIDE the body it is supposed to
 * authenticate, so «verified» proves the sender knew a token and nothing at all about the amount,
 * currency or status sitting beside it. The product therefore no longer settles money on what that
 * body says: it re-reads the charge from the gateway over its own connection, and refuses to settle
 * when it cannot.
 *
 * Which means a test that posts a webhook and expects an activation is, from now on, describing two
 * facts — «the gateway sent this» and «the gateway confirms it» — and has to supply both. Without
 * the fake the fetch fails, nothing settles, and a journey spec stops at the payment gate for a
 * reason that has nothing to do with what it was testing.
 *
 * ## Faked rather than bypassed, deliberately
 *
 * The alternative — letting tests skip the confirmation — would mean the suite exercises a path
 * production does not have, and the one guarantee this whole mechanism exists for would be the one
 * thing never tested. `PaymentActivationSecurityTest` uses the same fake to assert the opposite
 * cases: a forged body overridden, a short charge refused, an unreachable gateway settling nothing.
 */
trait ConfirmsGatewayPayments
{
    /** What the gateway will say next. Read when a request arrives, not when the stub is registered. */
    private ?array $gatewayAnswer = null;

    /**
     * Make the gateway agree with a charge we already hold.
     *
     * The figures come from the payment itself rather than from literals, so a repricing does not
     * quietly turn every settlement in the suite into an amount mismatch.
     *
     * ## Why the stub reads a property instead of closing over the payload
     *
     * `Http::fake()` MERGES its stubs rather than replacing them, and the first stub matching a URL
     * wins. A test that settles two charges therefore registered two stubs for the same pattern and
     * got the FIRST one both times — the second payment was checked against the first one's
     * reference and refused as a `reference_mismatch`, which reads exactly like a product defect and
     * is not one. Reading a property at call time means the latest answer is the one that arrives.
     *
     * @param  object{amount: mixed, currency: mixed, idempotency_key: mixed}  $payment
     * @param  int|null  $minorUnits  override the figure, in the smallest unit, to stage a short charge
     */
    protected function gatewayConfirms(object $payment, string $status = 'paid', ?int $minorUnits = null): void
    {
        $this->gatewayAnswer = [
            'id' => 'pay_confirmed',
            'status' => $status,
            // Halalas — the smallest unit, which is what the gateway reports.
            'amount' => $minorUnits ?? (int) round(((float) $payment->amount) * 100),
            'currency' => $payment->currency,
            'metadata' => ['reference' => $payment->idempotency_key],
        ];

        Http::fake([
            'api.moyasar.com/v1/payments/*' => fn () => Http::response($this->gatewayAnswer, 200),
        ]);
    }
}
