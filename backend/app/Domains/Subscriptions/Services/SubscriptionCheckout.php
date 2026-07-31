<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Billing\Providers\SubscriptionProviderRegistry;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Tenant;
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
    ) {}

    /**
     * The trial fee for an application that owes one.
     *
     * @return array{payment: SubscriptionPayment, status: string, checkout_url: ?string, refused: list<string>}
     */
    public function startTrial(RegistrationRequest $request, ?string $provider = null): array
    {
        $plan = $this->catalogue->byCode($request->plan_code);

        if ($plan === null || ! $plan->offersTrial()) {
            throw new RuntimeException('This application is not for a plan that offers a trial.');
        }

        /*
         * Refused before a charge is opened, not after.
         *
         * Taking the fee and then discovering the customer has had their trial would mean refunding
         * money we should never have asked for — and a refund is a worse experience than a refusal.
         * The payment-method identity is not known yet (it arrives with the webhook), so this is the
         * first of two checks; the second runs when the event lands.
         */
        $refused = $this->trials->reasonsToRefuse($request);

        if ($refused !== []) {
            return ['payment' => $this->refusedPayment($request, $plan->code), 'status' => 'refused', 'checkout_url' => null, 'refused' => $refused];
        }

        return $this->open(
            purpose: 'trial',
            amount: (string) $plan->trial_fee,
            currency: $plan->currency,
            planCode: $plan->code,
            interval: $request->billing_interval ?? 'monthly',
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

        $session = $adapter->createSession([
            'amount' => $amount,
            'currency' => $currency,
            'description' => $this->describe($purpose, $planCode),
            'reference' => $idempotencyKey,
            'return_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/signup/status',
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

        return [
            'payment' => $payment->refresh(),
            'status' => (string) $session['status'],
            'checkout_url' => $session['checkout_url'] ?? null,
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
            // One trial charge per application, whatever happens to the browser.
            return "trial:{$registration->getKey()}:{$planCode}:{$interval}";
        }

        // One charge per subscription PERIOD: the period end is what makes this month's retry the
        // same charge and next month's renewal a different one.
        $period = $subscription?->current_period_end?->toDateString() ?? 'initial';

        return "{$purpose}:{$subscription?->getKey()}:{$period}";
    }

    private function describe(string $purpose, ?string $planCode): string
    {
        return match ($purpose) {
            'trial' => "CampaignsHub trial — {$planCode}",
            'reactivation' => "CampaignsHub reactivation — {$planCode}",
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
    public function owesPayment(RegistrationRequest $request): bool
    {
        return in_array($request->state, [
            AccountState::ApprovedAwaitingPayment,
            AccountState::PaymentPending,
        ], true);
    }
}
