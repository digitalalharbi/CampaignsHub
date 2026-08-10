<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Billing\Providers\PaymentProvider;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPaymentMethod;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Frontend;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening a charge for the platform's own revenue (PAY-002).
 *
 * The whole of this class is "create a pending record, then ask the gateway for somewhere to pay". It
 * cannot mark anything paid and does not try: the result of a checkout is a URL, and a customer who
 * reaches it may abandon it, fail at it, or complete it — only a verified webhook knows which.
 *
 * The idempotency key is the point. It is derived from what the charge IS, not from the moment it was
 * requested, so pressing pay twice, a retried request, or a double-submitted form all resolve to the
 * SAME payment. That is what stops a customer being billed twice for one thing — the contract's
 * "منع تكرار الخصم" — and it is enforced by a unique index rather than by remembering to check.
 */
final class SubscriptionCheckout
{
    public function __construct(
        private readonly SubscriptionProviderRegistry $providers,
        private readonly PlanCatalogue $catalogue,
        private readonly TrialEligibility $trials,
        private readonly SubscriptionInvoicing $invoices,
        private readonly RecurringBilling $recurring,
    ) {}

    /**
     * What an application actually owes, on the plan it applied for (PLAN-PAID-001).
     *
     * There are two shapes and the plan decides which: a plan sold with a paid trial charges the
     * trial fee, and a plan sold outright charges the first period. Before the free tier was
     * withdrawn the second shape did not exist — every registration charge was a trial fee — and the
     * registration checkout threw for a plan that offered no trial. «البداية» is now such a plan, so
     * this is the fork that lets somebody buy it.
     *
     * The amount comes from the catalogue's own quote, so the figure charged is the figure the plan
     * page and the sign-up form showed. `refused` is only ever populated by the trial branch: trial
     * abuse is a rule about free-ish periods, and refusing an ordinary purchase for it would be
     * refusing a customer's money for having been a customer before.
     *
     * @return array{payment: SubscriptionPayment, status: string, checkout_url: ?string, refused: list<string>}
     */
    public function startRegistrationPayment(RegistrationRequest $request, ?string $provider = null): array
    {
        $plan = $this->catalogue->byCode($request->plan_code);

        if ($plan === null) {
            throw new RuntimeException('This application names no plan to charge for.');
        }

        $interval = (string) ($request->billing_interval ?? 'monthly');

        /*
         * The TERM decides, not the plan — PAY-AUDIT-003.
         *
         * This asked `offersTrial()`, which reads the plan and cannot see what is being bought, so an
         * annual applicant to a plan with an introductory month was charged the introductory price
         * and given a monthly renewal. The public quote said one thing and the charge did another,
         * which is the disagreement this product exists to refuse: the figure a customer authorises
         * must be the figure they were shown.
         */
        if ($plan->offersIntroFor($interval)) {
            return $this->startTrial($request, $provider);
        }
        $quote = $this->catalogue->quote($plan, $interval);

        if ($quote === null) {
            // The plan is not sold on this term. Charging the other term's price would bill a year
            // for a month, which is exactly what `priceFor()` returns null to prevent.
            throw new RuntimeException('This plan is not sold on the requested term.');
        }

        return $this->open(
            purpose: 'subscription',
            amount: $quote['due_now'],
            currency: $quote['currency'],
            planCode: $plan->code,
            interval: $interval,
            provider: $provider,
            registration: $request,
        ) + ['refused' => []];
    }

    /**
     * The trial fee for an application that owes one.
     *
     * @return array{payment: SubscriptionPayment, status: string, checkout_url: ?string, refused: list<string>}
     */
    public function startTrial(RegistrationRequest $request, ?string $provider = null): array
    {
        $plan = $this->catalogue->byCode($request->plan_code);

        $interval = (string) ($request->billing_interval ?? 'monthly');

        if ($plan === null || ! $plan->offersIntroFor($interval)) {
            throw new RuntimeException('This application is not for a plan and term that open with an introductory month.');
        }

        /*
         * Refused before a charge is opened, not after.
         *
         * Taking the fee and then discovering the customer has had their trial would mean refunding
         * money we should never have asked for — and a refund is a worse experience than a refusal.
         * The payment-method identity is not known yet (it arrives with the webhook), so this is the
         * first of two checks; the second runs when the event lands.
         */
        /*
         * A committed offer cannot be bought without agreeing to the commitment — SUB-CONSENT-001.
         *
         * Refused BEFORE the charge is opened, like trial abuse above and for the same reason: taking
         * money and then discovering the terms were never agreed means refunding money we should not
         * have asked for. `422` rather than a refused-payment row, because nothing was attempted —
         * this is the form being incomplete, not the customer being turned away.
         */
        if ($plan->commitmentMonthsFor($interval) > 0 && $request->commitment_consent_at === null) {
            throw new RuntimeException('The minimum commitment has not been agreed to.');
        }

        $refused = $this->trials->reasonsToRefuse($request);

        if ($refused !== []) {
            return ['payment' => $this->refusedPayment($request, $plan->code), 'status' => 'refused', 'checkout_url' => null, 'refused' => $refused];
        }

        return $this->open(
            purpose: 'trial',
            amount: (string) $plan->trial_fee,
            currency: $plan->currency,
            planCode: $plan->code,
            interval: $interval,
            provider: $provider,
            registration: $request,
        ) + ['refused' => []];
    }

    /**
     * A subscription charge for a workspace that already exists — the trial converting, a renewal, or
     * a reactivation.
     *
     * @return array{payment: SubscriptionPayment, status: string, checkout_url: ?string}
     */
    public function chargeSubscription(Subscription $subscription, string $purpose = 'subscription', ?string $provider = null): array
    {
        $tenant = Tenant::withoutGlobalScopes()->findOrFail($subscription->tenant_id);

        /*
         * The amount comes from the SUBSCRIPTION, not from the catalogue.
         *
         * `unit_amount` is what this customer was sold; the catalogue is what new customers are
         * quoted. Reading the catalogue here would re-price everybody on a plan the moment its price
         * was edited in /admin — which is the consequence PLAN-001 removed on purpose.
         */
        $amount = $subscription->unit_amount;

        if ($amount === null) {
            throw new RuntimeException('This subscription has no agreed price to charge.');
        }

        return $this->open(
            purpose: $purpose,
            amount: (string) $amount,
            currency: (string) ($subscription->currency ?? config('subscriptions.currency')),
            planCode: $subscription->plan?->code,
            interval: (string) $subscription->billing_interval,
            provider: $provider,
            tenant: $tenant,
            subscription: $subscription,
        );
    }

    /**
     * The prorated difference for a mid-term upgrade (PAY-002).
     *
     * Separate from `chargeSubscription` because the amount is NOT the subscription's agreed price:
     * it is the part-period difference computed by {@see SubscriptionProration}, and passing it in
     * is the whole point. Reading `unit_amount` here would charge a customer a full period for the
     * days they have left.
     *
     * What it deliberately does not do is change the plan. That happens when the gateway confirms
     * the money, through the same single call site as every other activation in this application.
     *
     * @return array{payment: SubscriptionPayment, status: string, checkout_url: ?string}
     */
    public function chargePlanChange(
        Subscription $subscription,
        SubscriptionPlan $newPlan,
        string $interval,
        string $amount,
        ?string $provider = null,
    ): array {
        $tenant = Tenant::withoutGlobalScopes()->findOrFail($subscription->tenant_id);

        if ((float) $amount <= 0) {
            throw new RuntimeException('A plan change with nothing to pay must not open a charge.');
        }

        return $this->open(
            purpose: 'plan_change',
            amount: $amount,
            currency: (string) ($subscription->currency ?? config('subscriptions.currency')),
            planCode: $newPlan->code,
            interval: $interval,
            provider: $provider,
            tenant: $tenant,
            subscription: $subscription,
        );
    }

    /**
     * @return array{payment: SubscriptionPayment, status: string, checkout_url: ?string}
     */
    private function open(
        string $purpose,
        string $amount,
        string $currency,
        ?string $planCode,
        string $interval,
        ?string $provider,
        ?RegistrationRequest $registration = null,
        ?Tenant $tenant = null,
        ?Subscription $subscription = null,
    ): array {
        $key = $this->providers->defaultKey();
        $providerKey = $provider ?? $key;
        $adapter = $this->providers->for($providerKey);

        /*
         * Derived from WHAT is being charged, never from when.
         *
         * A key containing a timestamp or a random value makes every retry a new charge, which is the
         * bug this is here to prevent. The period is part of it so next month's renewal is a
         * different charge and this month's retry is not.
         */
        $idempotencyKey = $this->idempotencyKeyFor($purpose, $registration, $subscription, $planCode, $interval);

        $existing = SubscriptionPayment::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            // Already opened. Hand back the same charge rather than a second one — and never re-ask
            // the gateway, because a settled payment must not get a fresh checkout URL.
            return [
                'payment' => $existing,
                'status' => $existing->status === 'pending' ? 'created' : $existing->status,
                'checkout_url' => $existing->status === 'pending' ? $existing->checkout_url : null,
            ];
        }

        $payment = DB::transaction(function () use (
            $purpose, $amount, $currency, $planCode, $interval, $providerKey, $idempotencyKey,
            $registration, $tenant, $subscription
        ): SubscriptionPayment {
            $payment = new SubscriptionPayment;
            $payment->forceFill([
                'registration_request_id' => $registration?->getKey(),
                'tenant_id' => $tenant?->getKey(),
                'subscription_id' => $subscription?->getKey(),
                'purpose' => $purpose,
                'plan_code' => $planCode,
                'billing_interval' => $interval,
                'provider' => $providerKey,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
            ])->save();

            return $payment->refresh();
        });

        /*
         * PAY-TOKEN-002 — a renewal with a card on file is taken, not asked for.
         *
         * This is the fork `RecurringBilling` was written against and deliberately left open: a
         * charge that BOTH created a hosted invoice and debited the saved card would ask the customer
         * to pay for something already taken from them. So it is one route or the other, and the
         * saved card wins only where the customer is genuinely absent.
         *
         * `subscription` is that case, and only that case — the daily sweep's renewals and trial
         * conversions. A registration, a plan change and a reactivation all happen with somebody
         * sitting in front of the screen, and they keep the hosted page: for a reactivation that is
         * the point, since the card that just failed is usually the one on file.
         *
         * Nothing here settles anything. `chargeStoredMethod` reports what the gateway said about the
         * ATTEMPT; the payment becomes paid through the verified webhook and the re-read in
         * `ApplySubscriptionPaymentEvent`, exactly as an attended one does.
         */
        $savedCard = $purpose === 'subscription' && $subscription !== null && $adapter->supportsUnattendedCharge()
            ? $this->recurring->methodFor($subscription)
            : null;

        $session = $savedCard !== null
            ? $this->takeFromSavedCard($adapter, $savedCard, $payment, [
                'amount' => $amount,
                'currency' => $currency,
                'description' => $this->describe($purpose, $planCode),
                'reference' => $idempotencyKey,
            ])
            : $adapter->createSession([
                'amount' => $amount,
                'currency' => $currency,
                'description' => $this->describe($purpose, $planCode),
                'reference' => $idempotencyKey,
                'return_url' => Frontend::origin().'/signup/status',
            ]);

        /*
         * `awaiting_credentials` is recorded as exactly that.
         *
         * Not `failed`, which would suggest the gateway refused; not `pending`, which would suggest a
         * customer is on their way to pay. No provider is configured, so nobody is paying anything,
         * and the account will not activate — which is the truthful outcome.
         */
        $payment->forceFill([
            'provider_session_id' => $session['session_id'] ?? null,
            'checkout_url' => $session['checkout_url'] ?? null,
            'status' => $session['status'] === 'awaiting_credentials' ? 'awaiting_credentials' : $payment->status,
            'error' => $session['error'] ?? null,
        ])->save();

        /*
         * The document exists from the moment the charge does (SUBINV-001).
         *
         * Not when it is paid: a customer is entitled to the invoice that says what they were asked
         * for whether or not they go on to pay it, and one conjured retrospectively from a payment
         * can show no due date and no unpaid balance.
         */
        $this->invoices->issueFor($payment->refresh());

        return [
            'payment' => $payment->refresh(),
            'status' => (string) $session['status'],
            'checkout_url' => $session['checkout_url'] ?? null,
        ];
    }

    /**
     * Take the renewal from the card on file, and report it in the shape a session would have.
     *
     * ## No URL, and that is the whole difference
     *
     * `checkout_url` comes back null because nobody is being sent anywhere to pay. Returning one
     * alongside a debited card is the double-charge this fork exists to prevent, and it would look
     * entirely normal in the interface right up until the customer's statement arrived.
     *
     * ## `submitted` is not `paid`
     *
     * The payment stays PENDING. The gateway has been asked to take money and has said it will try;
     * the money is only real once a verified webhook says so and `ApplySubscriptionPaymentEvent`
     * re-reads it from the gateway (PAY-CONFIRM-001). Marking it paid here would be a second, weaker
     * way to activate an account, on the word of the party being paid.
     *
     * ## A refusal is recorded, not retried behind a hosted page
     *
     * A failed attempt marks the payment `failed` and stops. It does NOT fall back to a checkout URL:
     * a refusal and a timeout are indistinguishable from here, and offering a page to pay for
     * something that may already have been taken is exactly the risk this is avoiding. The customer
     * is not stranded — the sweep moves the subscription to past due with its grace period, and the
     * reactivation path is attended and hosted, which is the right shape for a card that just failed.
     *
     * @param  array<string,mixed>  $payload
     * @return array{session_id: ?string, checkout_url: ?string, status: string, error: ?string}
     */
    private function takeFromSavedCard(
        PaymentProvider $adapter,
        SubscriptionPaymentMethod $method,
        SubscriptionPayment $payment,
        array $payload,
    ): array {
        $result = $adapter->chargeStoredMethod((string) $method->provider_token, $payload);

        $status = match ($result['status']) {
            'submitted' => 'created',
            'awaiting_credentials' => 'awaiting_credentials',
            default => 'failed',
        };

        $payment->forceFill([
            'provider_payment_id' => $result['provider_payment_id'] ?? null,
            'status' => $status === 'created' ? 'pending' : $status,
            'error' => $result['error'] ?? null,
        ])->save();

        if ($status === 'created') {
            $method->forceFill(['last_used_at' => now()])->save();
        }

        return [
            // No session was created, so `provider_session_id` stays empty. The gateway's own payment
            // id is on the payment, which is what the confirming webhook is matched against.
            'session_id' => null,
            'checkout_url' => null,
            'status' => $status,
            'error' => $result['error'] ?? null,
        ];
    }

    private function idempotencyKeyFor(
        string $purpose,
        ?RegistrationRequest $registration,
        ?Subscription $subscription,
        ?string $planCode,
        string $interval,
    ): string {
        if ($registration !== null) {
            /*
             * One registration charge per application, per plan, per term — whatever happens to the
             * browser. The purpose is part of the key because an application can owe either a trial
             * fee or a first period, and the two are different charges for different amounts; a key
             * that ignored it would hand a customer buying «البداية» outright the trial charge that
             * had been opened for them on some other plan.
             */
            return "{$purpose}:{$registration->getKey()}:{$planCode}:{$interval}";
        }

        // One charge per subscription PERIOD: the period end is what makes this month's retry the
        // same charge and next month's renewal a different one.
        $period = $subscription?->current_period_end?->toDateString() ?? 'initial';

        /*
         * A plan change is identified by WHICH plan, within this period.
         *
         * Without the plan code a customer who upgrades, changes their mind before paying, and picks
         * a different plan would be handed the first plan's charge back — the same amount for the
         * wrong thing. With it, retrying the same choice is still one charge.
         */
        if ($purpose === 'plan_change') {
            return "plan_change:{$subscription?->getKey()}:{$period}:{$planCode}:{$interval}";
        }

        return "{$purpose}:{$subscription?->getKey()}:{$period}";
    }

    private function describe(string $purpose, ?string $planCode): string
    {
        return match ($purpose) {
            'trial' => "CampaignsHub trial — {$planCode}",
            'reactivation' => "CampaignsHub reactivation — {$planCode}",
            'plan_change' => "CampaignsHub plan change — {$planCode}",
            default => "CampaignsHub subscription — {$planCode}",
        };
    }

    /**
     * A charge that was never opened, recorded so the refusal is visible rather than silent.
     *
     * The applicant is told their trial cannot be started; the review queue can see why. Creating
     * nothing at all would leave an operator with an application stuck at the payment gate and no
     * explanation anywhere.
     */
    private function refusedPayment(RegistrationRequest $request, string $planCode): SubscriptionPayment
    {
        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'registration_request_id' => $request->getKey(),
            'purpose' => 'trial',
            'plan_code' => $planCode,
            'provider' => $this->providers->defaultKey(),
            'amount' => '0.00',
            'currency' => (string) config('subscriptions.currency'),
            'status' => 'refused',
            'idempotency_key' => 'refused:'.$request->getKey().':'.$planCode,
            'error' => 'A trial has already been used by this customer.',
        ])->save();

        return $payment->refresh();
    }

    /** True when this application is sitting at a payment gate it has not cleared. */
    /**
     * Does this application still owe an agreement to a minimum commitment? — SUB-CONSENT-001.
     *
     * Asked by the controller so the refusal can be a 422 the customer can act on, rather than the
     * exception below rendering as a 500. The exception stays as the backstop: a caller reaching this
     * service directly — a console command, a future admin action — must not be able to open a
     * committed charge on terms nobody agreed to just because it skipped the controller.
     */
    public function requiresCommitmentConsent(RegistrationRequest $request): bool
    {
        $plan = $this->catalogue->byCode($request->plan_code);

        if ($plan === null) {
            return false;
        }

        return $plan->commitmentMonthsFor((string) ($request->billing_interval ?? 'monthly')) > 0
            && $request->commitment_consent_at === null;
    }

    public function owesPayment(RegistrationRequest $request): bool
    {
        return in_array($request->state, [
            AccountState::ApprovedAwaitingPayment,
            AccountState::PaymentPending,
        ], true);
    }
}
