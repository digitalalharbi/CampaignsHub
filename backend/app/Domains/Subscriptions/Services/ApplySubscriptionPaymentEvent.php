<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Providers\PaymentProvider;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPaymentMethod;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way a payment becomes real (PAY-002).
 *
 * Everything in the product that depends on money having moved depends on this class, and this class
 * depends on one thing: an event the adapter cryptographically verified. Nothing else — not returning
 * from the gateway's page, not a status the browser reports, not an administrator's assurance — can
 * reach `AdvanceRegistration::paymentConfirmed()`.
 *
 * Three guarantees, each structural rather than remembered:
 *
 * 1. **Unverified events do nothing.** The adapter says `verified: false` and this returns before
 *    touching a payment. The event is still RECORDED, because a stream of failed verifications is
 *    what an attack looks like and discarding it would hide that.
 * 2. **A re-delivered event is a no-op.** `payment_webhook_events.event_id` is unique, so the second
 *    delivery loses at the database. Gateways retry by design; without this a retry is a second
 *    activation, a second trial claim, and — on a refund event — a second reversal.
 * 3. **The amount is re-checked.** A verified event proves the gateway sent it, not that it says what
 *    we expect. A "paid" event for less than the charge settles nothing.
 */
final class ApplySubscriptionPaymentEvent
{
    public function __construct(
        private readonly SubscriptionProviderRegistry $providers,
        private readonly AdvanceRegistration $advance,
        private readonly TrialEligibility $trials,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly SubscriptionInvoicing $invoices,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
        private readonly RecurringBilling $recurring,
    ) {}

    /**
     * @param  array<string,string>  $headers
     */
    public function handle(string $provider, string $rawBody, array $headers): PaymentWebhookEvent
    {
        // Webhooks arrive with no session and belong to no tenant until we work out which payment they
        // refer to, so every read below is deliberately cross-tenant.
        $this->tenants->enterPlatformScope();

        $adapter = $this->providers->for($provider);
        $result = $adapter->verifyWebhook($rawBody, $headers);
        $verified = (bool) ($result['verified'] ?? false);

        /*
         * An unverified event still gets a row.
         *
         * It is the only trace that somebody tried, and a burst of them is the signature of an
         * attacker probing the endpoint. The id is deliberately not taken from the payload here —
         * an unverified body must not be able to occupy the id of a real event and make the genuine
         * delivery look like a duplicate.
         */
        if (! $verified) {
            return $this->record($provider, 'unverified:'.hash('sha256', $rawBody), null, false, $rawBody, processed: true);
        }

        $eventId = (string) ($result['event_id'] ?? '');
        $eventId = $eventId !== '' ? $eventId : 'anonymous:'.hash('sha256', $rawBody);

        $existing = PaymentWebhookEvent::query()->where('event_id', $eventId)->first();

        if ($existing !== null) {
            // Already seen. Gateways retry by design; applying twice would activate twice, claim the
            // trial twice, and reverse a refund twice.
            return $existing;
        }

        $event = $this->record(
            $provider,
            $eventId,
            (string) ($result['type'] ?? ''),
            true,
            $rawBody,
            processed: false,
        );

        $payment = $this->matchPayment($provider, $result);

        if ($payment !== null) {
            $this->applyTo($payment, $result, $adapter, $adapter->paymentMethodFingerprint((array) ($result['payload'] ?? [])));
        }

        $event->forceFill(['processed_at' => Carbon::now()])->save();

        return $event->refresh();
    }

    /**
     * Move the payment, and only then let anything downstream happen.
     *
     * @param  array<string,mixed>  $result
     */
    private function applyTo(SubscriptionPayment $payment, array $result, PaymentProvider $adapter, ?string $fingerprint): void
    {
        $status = (string) ($result['status'] ?? 'pending');

        /*
         * A settled payment is not re-settled.
         *
         * Two different events can both say "paid" — a checkout completion and a payment-intent
         * success for the same money. Guarding on the payment's own state means the second one is
         * inert even though its event id is new.
         */
        if ($payment->isPaid() && $status === 'paid') {
            return;
        }

        /*
         * PAY-CONFIRM-001 — where the webhook cannot prove its own body, ask the gateway.
         *
         * Moyasar authenticates with a shared secret carried INSIDE the payload, so «verified» there
         * means the sender knew a token — not that the amount, currency or status beside it are the
         * gateway's words. Anyone who learns that token can post a paid event for any figure and it
         * verifies. So before money settles, the figures are re-read backend-to-backend over our own
         * connection, with an id taken from the verified event and credentials the caller never had.
         *
         * Stripe and the sandbox sign the raw body, so their events are already attested and nothing
         * is asked — a round trip there would only add a failure mode to a path that does not need it.
         */
        if ($status === 'paid' && ! $adapter->confirmsPayloadIntegrity()) {
            $confirmed = $this->confirm($payment, $result, $adapter);

            if ($confirmed === null) {
                return;
            }

            $result = $confirmed;
            $status = (string) ($result['status'] ?? 'pending');

            /*
             * The gateway may disagree with the event about the STATUS too, and its answer wins.
             * A «paid» webhook over a payment the gateway still calls pending settles nothing.
             */
            if ($status !== 'paid') {
                $this->audit->log(
                    action: 'subscription.payment.event_contradicted',
                    entityType: SubscriptionPayment::class,
                    entityId: (string) $payment->getKey(),
                    after: ['event_said' => 'paid', 'gateway_says' => $status],
                    reason: 'The webhook claimed a paid charge the gateway does not report as paid.',
                );

                return;
            }
        }

        if ($status === 'paid' && ! $this->chargeMatches($payment, $result)) {
            /*
             * A verified event proves the gateway sent it. It does not prove the gateway charged what
             * we asked for — a partial capture, a currency mismatch, or a payload we have misread all
             * arrive verified. Recording and refusing is the safe answer: a human can settle it, and
             * meanwhile nothing has been activated.
             */
            $payment->forceFill([
                'status' => 'failed',
                'error' => 'The confirmed charge does not match what was quoted.',
            ])->save();

            $this->audit->log(
                action: 'subscription.payment.amount_mismatch',
                entityType: SubscriptionPayment::class,
                entityId: (string) $payment->getKey(),
                after: [
                    'expected' => (string) $payment->amount,
                    'received' => $result['amount'] ?? null,
                    'expected_currency' => (string) $payment->currency,
                    'received_currency' => $result['currency'] ?? null,
                ],
            );

            return;
        }

        /*
         * PAY-TOKEN-003 — the card this settled charge leaves behind.
         *
         * Read from the SAME verified event that is about to settle money, and only when it settles:
         * a failed or pending charge proves nothing about a card being reusable, and storing one from
         * it would hand the renewal sweep a token the gateway never confirmed.
         *
         * Most events yield null — no provider here but Moyasar publishes a token at all, and a
         * Moyasar payment made without a reusable source publishes none either. Null costs the
         * customer nothing: their renewal stays the attended invoice it is today.
         */
        $card = $status === 'paid'
            ? $adapter->savedPaymentMethodFrom((array) ($result['payload'] ?? []))
            : null;

        DB::transaction(function () use ($payment, $result, $status, $fingerprint, $card): void {
            $payment->forceFill([
                'status' => $status,
                'provider_payment_id' => $result['payment_id'] ?? $payment->provider_payment_id,
                'paid_at' => $status === 'paid' ? Carbon::now() : $payment->paid_at,
                'refunded_at' => $status === 'refunded' ? Carbon::now() : $payment->refunded_at,
            ])->save();

            $this->audit->log(
                action: 'subscription.payment.'.$status,
                entityType: SubscriptionPayment::class,
                entityId: (string) $payment->getKey(),
                after: ['purpose' => $payment->purpose, 'amount' => (string) $payment->amount],
            );

            match ($status) {
                'paid' => $this->settle($payment->refresh(), $fingerprint, $card),
                'refunded', 'disputed' => $this->reverse($payment->refresh()),
                'failed' => $this->failed($payment->refresh()),
                default => null,
            };

            /*
             * The document follows the money — and follows the PROVISIONING too.
             *
             * Deliberately after `settle()`: the very first invoice a customer receives is issued
             * before their workspace exists, and it is `settle()` that creates the workspace. Marking
             * the document paid first left it attached to no tenant, so the customer had been charged
             * for something they could not see a document for.
             */
            match ($status) {
                'paid' => $this->invoices->settle($payment->refresh()),
                'refunded' => $this->invoices->refund($payment->refresh(), 'The gateway reported a refund.'),
                default => null,
            };
        });
    }

    /**
     * What a confirmed payment actually buys.
     *
     * This is the single call site of `paymentConfirmed()` in the entire application. Everything the
     * contract says about activation happening only from a verified webhook reduces to that fact.
     *
     * @param  array{token: string, customer_id?: ?string, brand?: ?string, last4?: ?string, exp_month?: ?int, exp_year?: ?int}|null  $card
     */
    private function settle(SubscriptionPayment $payment, ?string $fingerprint, ?array $card = null): void
    {
        if ($payment->registration_request_id !== null) {
            $request = RegistrationRequest::query()->find($payment->registration_request_id);

            if ($request === null) {
                return;
            }

            /*
             * Buying a plan outright is not starting a trial (PLAN-PAID-001).
             *
             * The trial rules below exist to stop the same person taking a near-free look at the
             * product repeatedly. Applying them to a customer paying «البداية» in full would refuse a
             * genuine purchase — and worse, mark the money `refund_due` — for the offence of having
             * been a customer before. The purpose the charge was opened with is what separates them.
             */
            $isTrial = $payment->purpose === 'trial';

            /*
             * The second trial check, with the identity only the gateway could tell us.
             *
             * The first ran before the charge was opened, on the details the applicant typed. This one
             * knows the payment method, which is the identity hardest to vary — and it is the reason a
             * trial fee is charged at all rather than given away free.
             */
            if ($isTrial && $fingerprint !== null && ! $this->trials->mayStartTrial($request, $fingerprint)) {
                $payment->forceFill([
                    'status' => 'refund_due',
                    'error' => 'A trial has already been used by this payment method.',
                ])->save();

                $this->audit->log(
                    action: 'subscription.trial.refused_after_payment',
                    entityType: RegistrationRequest::class,
                    entityId: (string) $request->getKey(),
                    reason: 'The payment method has already been used for a trial; the fee is owed back.',
                );

                return;
            }

            if ($isTrial) {
                $this->trials->claim($request, null, $fingerprint);
            }

            // THE crossing. Nothing else in the codebase calls this.
            $advanced = $this->advance->paymentConfirmed($request);

            /*
             * The chain the brief asks for, in the order it asks for it: the account is advanced, the
             * workspace is provisioned by `ProvisionWorkspace` (with its first project, membership and
             * role), and only then does the subscription that the money bought come into existence.
             *
             * A trial subscription and a paid one are different states with different end dates, so
             * they are different calls rather than one call with a flag.
             */
            if ($advanced->isProvisioned() && $advanced->tenant !== null) {
                $isTrial
                    ? $this->lifecycle->beginTrial($advanced->tenant, $advanced, $payment)
                    : $this->lifecycle->beginSubscription($advanced->tenant, $advanced, $payment);

                /*
                 * The card is remembered AFTER provisioning, because the tenant it belongs to did not
                 * exist a moment ago. This is the first payment a customer ever makes, and the one
                 * whose card matters most: it is the card their renewal will be taken from.
                 */
                $this->rememberCard((string) $advanced->tenant->getKey(), $payment, $card);
            }

            return;
        }

        if ($payment->subscription_id === null) {
            return;
        }

        // A renewal, a conversion or an upgrade — the tenant has been on the payment since the charge
        // was opened, so there is nothing to wait for.
        $this->rememberCard($payment->tenant_id === null ? null : (string) $payment->tenant_id, $payment, $card);

        /*
         * A plan change is not a renewal, and must not be treated as one.
         *
         * `renewalPaid` moves the period end forward a whole month or year — applying it to a
         * part-period upgrade would hand the customer free time they did not buy, on top of the plan
         * they did. This is the only place an upgrade takes effect.
         */
        if ($payment->purpose === 'plan_change') {
            $this->lifecycle->planChangePaid($payment);

            return;
        }

        $this->lifecycle->renewalPaid($payment);
    }

    /**
     * Put the card on file, so the next renewal does not have to be asked for (PAY-TOKEN-003).
     *
     * ## Why this is allowed to happen without asking again
     *
     * The customer agreed to automatic renewal before this charge was opened — that is what
     * SUB-CONSENT-001's disclosure gate is, and the checkbox the Pay button waits on covers the
     * renewal date and the commitment explicitly. Keeping the token the gateway issued is how that
     * agreement is honoured; a product that promised automatic renewal and then emailed an invoice
     * every month would be the thing that broke it. A customer who changes their mind detaches the
     * card from their own billing page, and the renewal goes back to a hosted invoice.
     *
     * ## Never at the cost of the payment
     *
     * Storing a card is bookkeeping; the money has already moved and the account is already
     * provisioned. So a failure here is recorded and swallowed rather than allowed to roll back the
     * settlement around it — a customer whose card could not be filed still bought what they paid
     * for, and their renewal simply falls back to the attended invoice.
     *
     * @param  array{token: string, customer_id?: ?string, brand?: ?string, last4?: ?string, exp_month?: ?int, exp_year?: ?int}|null  $card
     */
    private function rememberCard(?string $tenantId, SubscriptionPayment $payment, ?array $card): void
    {
        if ($card === null || $tenantId === null || ($card['token'] ?? '') === '') {
            return;
        }

        try {
            $method = $this->recurring->remember($tenantId, (string) $payment->provider, $card);

            /*
             * The LABEL is audited, never the token.
             *
             * An audit trail is read by support staff and exported to whoever asks for it, and a
             * bearer credential for somebody's card does not belong in either. «Visa ···· 4242» is
             * enough to answer every question this row exists to answer.
             */
            $this->audit->log(
                action: 'subscription.payment_method.saved',
                entityType: SubscriptionPaymentMethod::class,
                entityId: (string) $method->getKey(),
                after: ['provider' => (string) $payment->provider, 'card' => $method->label()],
                reason: 'The gateway issued a reusable token with a settled payment, so renewals can be taken without asking again.',
            );
        } catch (\Throwable $e) {
            $this->audit->log(
                action: 'subscription.payment_method.not_saved',
                entityType: SubscriptionPayment::class,
                entityId: (string) $payment->getKey(),
                after: ['provider' => (string) $payment->provider],
                reason: 'The payment settled, but the card could not be stored: '.$e->getMessage(),
            );
        }
    }

    private function reverse(SubscriptionPayment $payment): void
    {
        if ($payment->subscription_id !== null) {
            $this->lifecycle->paymentReversed($payment);
        }
    }

    private function failed(SubscriptionPayment $payment): void
    {
        if ($payment->subscription_id !== null) {
            $this->lifecycle->renewalFailed($payment);
        }
    }

    /**
     * The charge this event refers to.
     *
     * Matched on OUR reference first — the idempotency key we handed the gateway and it handed back —
     * because a provider payment id is only known after the first event about it.
     *
     * @param  array<string,mixed>  $result
     */
    private function matchPayment(string $provider, array $result): ?SubscriptionPayment
    {
        $reference = (string) ($result['reference'] ?? '');

        if ($reference !== '') {
            $payment = SubscriptionPayment::query()->where('idempotency_key', $reference)->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        $providerPaymentId = (string) ($result['payment_id'] ?? '');

        if ($providerPaymentId === '') {
            return null;
        }

        return SubscriptionPayment::query()
            ->where('provider', $provider)
            ->where(function ($query) use ($providerPaymentId): void {
                $query->where('provider_payment_id', $providerPaymentId)
                    ->orWhere('provider_session_id', $providerPaymentId);
            })
            ->first();
    }

    /**
     * Re-read the charge from the gateway, and refuse to settle without an answer.
     *
     * ## Why null must not mean «carry on»
     *
     * The whole point is that this adapter's webhook body is not trustworthy on its own. Falling
     * back to it when the gateway cannot be reached would make the guarantee opt-out for anybody who
     * can make one HTTP request fail — which, for a network they are already on, is not a high bar.
     *
     * So an unreachable gateway leaves the payment exactly where it was and records why. Nothing is
     * marked `failed`: nothing is known to be wrong with the money, only unconfirmed, and calling it
     * failed would fire `renewalFailed` and dunning at a customer who has paid. Gateways redeliver,
     * and the next delivery re-asks.
     *
     * ## The reference is checked too
     *
     * A payment id from an event that resolved to OUR charge could still belong to a different one —
     * so when the gateway states a reference, it must be the idempotency key of the payment about to
     * be settled. That closes the shape where a real, verified, genuinely-paid event for somebody
     * else's small charge is pointed at somebody's large invoice.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>|null the gateway's own figures, or null when it could not be asked
     */
    private function confirm(SubscriptionPayment $payment, array $result, PaymentProvider $adapter): ?array
    {
        $providerPaymentId = (string) ($result['payment_id'] ?? '');
        $fetched = $providerPaymentId === '' ? null : $adapter->fetchPayment($providerPaymentId);

        if ($fetched === null) {
            $this->audit->log(
                action: 'subscription.payment.unconfirmed',
                entityType: SubscriptionPayment::class,
                entityId: (string) $payment->getKey(),
                after: ['provider' => $payment->provider, 'provider_payment_id' => $providerPaymentId ?: null],
                reason: 'The gateway could not be asked to confirm this charge, so nothing was settled.',
            );

            return null;
        }

        $statedReference = (string) ($fetched['reference'] ?? '');

        if ($statedReference !== '' && $statedReference !== (string) $payment->idempotency_key) {
            $this->audit->log(
                action: 'subscription.payment.reference_mismatch',
                entityType: SubscriptionPayment::class,
                entityId: (string) $payment->getKey(),
                after: ['expected' => (string) $payment->idempotency_key, 'gateway_says' => $statedReference],
                reason: 'The gateway attributes this charge to a different reference.',
            );

            return null;
        }

        /*
         * The event's identity is kept; only the MONEY is replaced.
         *
         * `payment_id` and `payload` still describe the delivery that arrived — the audit trail
         * should say which event triggered this — while status, amount and currency now come from
         * the gateway, because those are the three the caller could otherwise have chosen.
         */
        return [
            ...$result,
            'status' => (string) ($fetched['status'] ?? 'pending'),
            'amount' => $fetched['amount'] ?? null,
            'currency' => $fetched['currency'] ?? null,
        ];
    }

    /**
     * Does the confirmed charge match the one we quoted — the FIGURE and the CURRENCY?
     *
     * Compare to the fils, not to the float.
     *
     * The currency half was missing, and «49.00» is not a charge: subscriptions are sold in USD
     * (PAY-AUDIT-002) while both live gateways here default a stated currency to SAR, so a verified
     * event reading `49.00 SAR` settled a `49.00 USD` invoice and activated the account for about a
     * quarter of the price. Amount without currency is a number, not money.
     *
     * @param  array<string,mixed>  $result
     */
    private function chargeMatches(SubscriptionPayment $payment, array $result): bool
    {
        $received = $result['amount'] ?? null;
        $receivedCurrency = $result['currency'] ?? null;

        /*
         * A provider that does not state a figure is taken at its word on the rest; refusing every
         * such event would break gateways whose confirmation payload simply omits it. The same
         * latitude — and only the same — is given to a missing currency.
         */
        if ($received !== null && bccomp((string) $payment->amount, (string) $received, 2) !== 0) {
            return false;
        }

        if (is_string($receivedCurrency) && $receivedCurrency !== '') {
            // Upper-cased on both sides so `usd` and `USD` cannot read as two different currencies —
            // the same normalisation `/admin` applies when a plan's currency is edited.
            return strtoupper($receivedCurrency) === strtoupper((string) $payment->currency);
        }

        return true;
    }

    private function record(
        string $provider,
        string $eventId,
        ?string $type,
        bool $verified,
        string $rawBody,
        bool $processed,
    ): PaymentWebhookEvent {
        $event = new PaymentWebhookEvent;
        $event->forceFill([
            'tenant_id' => null,
            'provider' => $provider,
            'event_id' => $eventId,
            'type' => $type,
            'verified' => $verified,
            'payload' => json_decode($rawBody, true) ?: ['raw' => mb_substr($rawBody, 0, 2000)],
            'processed_at' => $processed ? Carbon::now() : null,
        ])->save();

        return $event->refresh();
    }
}
