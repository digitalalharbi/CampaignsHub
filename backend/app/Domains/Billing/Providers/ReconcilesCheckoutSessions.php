<?php

declare(strict_types=1);

namespace App\Domains\Billing\Providers;

/**
 * PAY-RECONCILE-001 — a gateway that can be asked, after the fact, whether a checkout it opened was paid.
 *
 * Only a gateway whose webhook can be lost for good needs it: Moyasar drops a delivery after its retries. The answer is a payment id
 * to CONFIRM, never proof by itself — settlement still re-reads that payment and checks its reference, amount and currency.
 */
interface ReconcilesCheckoutSessions
{
    /** The gateway's id of the payment that settled this checkout session, or null while it is unpaid or cannot be read. */
    public function paidPaymentIdForSession(string $sessionId): ?string;
}
