<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Models\PaymentWebhookEvent;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
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
            $this->applyTo($payment, $result, $adapter->paymentMethodFingerprint((array) ($result['payload'] ?? [])));
        }

        $event->forceFill(['processed_at' => Carbon::now()])->save();

        return $event->refresh();
    }

    /**
     * Move the payment, and only then let anything downstream happen.
     *
     * @param  array<string,mixed>  $result
     */
    private function applyTo(SubscriptionPayment $payment, array $result, ?string $fingerprint): void
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

        if ($status === 'paid' && ! $this->amountMatches($payment, $result)) {
            /*
             * A verified event proves the gateway sent it. It does not prove the gateway charged what
             * we asked for — a partial capture, a currency mismatch, or a payload we have misread all
             * arrive verified. Recording and refusing is the safe answer: a human can settle it, and
             * meanwhile nothing has been activated.
             */
            $payment->forceFill([
                'status' => 'failed',
                'error' => 'The confirmed amount does not match the charge.',
            ])->save();

            $this->audit->log(
                action: 'subscription.payment.amount_mismatch',
                entityType: SubscriptionPayment::class,
                entityId: (string) $payment->getKey(),
                after: ['expected' => (string) $payment->amount, 'received' => $result['amount'] ?? null],
            );

            return;
        }

        DB::transaction(function () use ($payment, $result, $status, $fingerprint): void {
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
                'paid' => $this->settle($payment->refresh(), $fingerprint),
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
     */
    private function settle(SubscriptionPayment $payment, ?string $fingerprint): void
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
            }

            return;
        }

        if ($payment->subscription_id === null) {
            return;
        }

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
     * Compare to the fils, not to the float.
     *
     * @param  array<string,mixed>  $result
     */
    private function amountMatches(SubscriptionPayment $payment, array $result): bool
    {
        $received = $result['amount'] ?? null;

        // A provider that does not state an amount is taken at its word on the rest; refusing every
        // such event would break gateways whose confirmation payload simply omits it.
        if ($received === null) {
            return true;
        }

        return bccomp((string) $payment->amount, (string) $received, 2) === 0;
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
