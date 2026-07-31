<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The self-operating part: what happens to an account over time (PAY-003).
 *
 * A trial converts, a renewal succeeds or fails, a failure gets a grace period, an unpaid account is
 * suspended, a suspended one can be brought back. None of it needs a person, and all of it is driven
 * by dates and confirmed payments rather than by anyone remembering to look.
 *
 * Two rules run through every method here:
 *
 * - **Suspension preserves data.** The contract is explicit — «عدم حذف بيانات العميل عند التعليق». A
 *   suspended workspace keeps every project, campaign and report; what it loses is access. Anything
 *   that deleted on suspension would make non-payment unrecoverable, which is not a business model.
 * - **A trial converts only with recorded consent.** `auto_convert_consent_at` is a timestamp, and a
 *   null one means the charge was never authorised. A trial without it is CANCELLED at the end rather
 *   than billed.
 */
final class SubscriptionLifecycle
{
    public function __construct(
        private readonly PlanCatalogue $catalogue,
        private readonly SubscriptionService $subscriptions,
        private readonly TransitionAccountState $transitions,
        private readonly AuditLogger $audit,
    ) {}

    // ── Starting ──────────────────────────────────────────────────────────────────────────────

    /**
     * A confirmed trial fee starts the trial (PAY-002 → PAY-003).
     *
     * Called only from `ApplySubscriptionPaymentEvent` once the fee is settled, so a subscription in
     * `trialing` is always one somebody actually paid for.
     */
    public function beginTrial(Tenant $tenant, RegistrationRequest $request, SubscriptionPayment $payment): Subscription
    {
        $plan = $this->catalogue->byCode($request->plan_code);

        if ($plan === null) {
            throw new \RuntimeException('The application names no plan to start a trial on.');
        }

        $interval = (string) ($request->billing_interval ?? 'monthly');
        $trialEnds = Carbon::now()->addDays(max(1, $plan->trial_days));

        $subscription = $this->subscriptions->assignPlan(
            $tenant,
            $plan,
            status: 'trialing',
            currentPeriodEnd: $trialEnds,
            interval: $interval,
        );

        $subscription->forceFill([
            'trial_ends_at' => $trialEnds,
            /*
             * Consent is recorded from the application, because that is where it was given: the
             * sign-up states that the trial converts, and choosing a plan and paying the fee IS the
             * agreement. Recording the moment rather than a flag means we can say WHEN, which is what
             * a customer disputing a charge will ask.
             */
            'auto_convert_consent_at' => Carbon::now(),
            'provider' => $payment->provider,
        ])->save();

        $this->audit->log(
            action: 'subscription.trial.started',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            after: ['plan' => $plan->code, 'interval' => $interval, 'trial_ends_at' => $trialEnds->toIso8601String()],
            tenantId: (string) $tenant->getKey(),
        );

        return $subscription->refresh();
    }

    // ── The daily sweep ───────────────────────────────────────────────────────────────────────

    /**
     * Everything that has come due.
     *
     * Returns what it did rather than logging and forgetting, so the scheduled command can report and
     * a test can assert on it.
     *
     * @return array<string, int>
     */
    public function runDueWork(?SubscriptionCheckout $checkout = null): array
    {
        return [
            'trials_converted' => $this->convertDueTrials($checkout),
            'renewals_charged' => $this->chargeDueRenewals($checkout),
            'marked_past_due' => $this->markPastDue(),
            'suspended' => $this->suspendAfterGrace(),
        ];
    }

    /**
     * Trials whose day has come.
     *
     * With consent, a charge is opened for the plan's real price and the subscription waits — it does
     * NOT become `active` here, because the money has not moved yet. Without consent, the trial simply
     * ends: billing somebody who never agreed to be billed is the thing this branch exists to prevent.
     */
    public function convertDueTrials(?SubscriptionCheckout $checkout = null): int
    {
        $due = $this->query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', Carbon::now())
            ->get();

        $converted = 0;

        foreach ($due as $subscription) {
            if (! $subscription->mayAutoConvert()) {
                $this->cancel($subscription, 'The trial ended and no consent to convert was recorded.');

                continue;
            }

            $subscription->forceFill([
                'status' => 'past_due',
                'current_period_end' => $this->nextPeriodEnd($subscription, Carbon::now()),
            ])->save();

            $checkout?->chargeSubscription($subscription->refresh(), 'subscription');

            $this->audit->log(
                action: 'subscription.trial.converted',
                entityType: Subscription::class,
                entityId: (string) $subscription->getKey(),
                after: ['interval' => $subscription->billing_interval, 'amount' => (string) $subscription->unit_amount],
                tenantId: (string) $subscription->tenant_id,
            );

            $converted++;
        }

        return $converted;
    }

    /** Active subscriptions whose period has ended: open the renewal charge, do not extend anything yet. */
    public function chargeDueRenewals(?SubscriptionCheckout $checkout = null): int
    {
        $due = $this->query()
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', Carbon::now())
            ->get();

        foreach ($due as $subscription) {
            if ($subscription->cancel_at_period_end) {
                $this->cancel($subscription, 'Cancelled at the customer’s request at the end of the period.');

                continue;
            }

            $checkout?->chargeSubscription($subscription, 'subscription');
        }

        return $due->count();
    }

    /**
     * A period that ended without a confirmed payment is PAST DUE, not suspended.
     *
     * Past due is an operating state — the contract lists it among the states where the account still
     * works — because a card that expired is not a customer who left. The grace period is what turns
     * one into the other.
     */
    public function markPastDue(): int
    {
        $lapsed = $this->query()
            ->whereIn('status', ['active'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', Carbon::now())
            ->get()
            ->filter(fn (Subscription $s) => ! $this->hasSettledChargeForPeriod($s));

        foreach ($lapsed as $subscription) {
            $this->enterPastDue($subscription, 'The renewal was not confirmed before the period ended.');
        }

        return $lapsed->count();
    }

    /** Grace expired and still unpaid: suspend, keeping every row the customer owns. */
    public function suspendAfterGrace(): int
    {
        $expired = $this->query()
            ->where('status', 'past_due')
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', Carbon::now())
            ->get();

        foreach ($expired as $subscription) {
            $this->suspend($subscription, 'The grace period ended without a confirmed payment.');
        }

        return $expired->count();
    }

    // ── Transitions ───────────────────────────────────────────────────────────────────────────

    public function enterPastDue(Subscription $subscription, string $why): Subscription
    {
        $graceDays = (int) config('subscriptions.grace_days', 7);

        $subscription->forceFill([
            'status' => 'past_due',
            // Stamped on the row, so a customer given longer keeps it even if the default changes.
            'grace_ends_at' => $subscription->grace_ends_at ?? Carbon::now()->addDays($graceDays),
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            // PastDue is OPERATIONAL — the account keeps working while the customer sorts the card out.
            $this->transitions->execute($tenant, AccountState::PastDue, $why);
        }

        return $subscription->refresh();
    }

    /**
     * Suspend — access stops, data stays.
     *
     * Nothing is deleted here and nothing ever should be: «عدم حذف بيانات العميل عند التعليق». A
     * suspended workspace that lost its campaigns would make non-payment unrecoverable.
     */
    public function suspend(Subscription $subscription, string $why): Subscription
    {
        $subscription->forceFill(['status' => 'suspended'])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->transitions->execute($tenant, AccountState::Suspended, $why);
        }

        return $subscription->refresh();
    }

    /** A confirmed renewal: the period moves, grace is cleared, the account operates again. */
    public function renewalPaid(SubscriptionPayment $payment): void
    {
        $subscription = $this->find($payment->subscription_id);

        if ($subscription === null) {
            return;
        }

        $subscription->forceFill([
            'status' => 'active',
            'grace_ends_at' => null,
            'trial_ends_at' => null,
            'current_period_end' => $this->nextPeriodEnd($subscription, Carbon::now()),
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->transitions->execute($tenant, AccountState::Active, 'A renewal was confirmed by the gateway.');
        }

        $this->audit->log(
            action: 'subscription.renewed',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            after: ['period_end' => $subscription->refresh()->current_period_end?->toIso8601String()],
            tenantId: (string) $subscription->tenant_id,
        );
    }

    /** A refused renewal starts the clock rather than ending the account. */
    public function renewalFailed(SubscriptionPayment $payment): void
    {
        $subscription = $this->find($payment->subscription_id);

        if ($subscription !== null) {
            $this->enterPastDue($subscription, 'The gateway refused the renewal.');
        }
    }

    /**
     * Money taken back — a refund or a dispute.
     *
     * Treated the same way a failed renewal is: the account goes past due with a grace period rather
     * than off a cliff. A chargeback is often a customer who does not recognise a line on a statement,
     * and cutting them off instantly is how a support question becomes a lost account.
     */
    public function paymentReversed(SubscriptionPayment $payment): void
    {
        $subscription = $this->find($payment->subscription_id);

        if ($subscription !== null) {
            $this->enterPastDue($subscription, 'A settled payment was refunded or disputed.');
        }
    }

    public function cancel(Subscription $subscription, string $why, bool $atPeriodEnd = false): Subscription
    {
        if ($atPeriodEnd) {
            // The customer keeps what they paid for. Cancelling immediately would take away time
            // already bought.
            $subscription->forceFill(['cancel_at_period_end' => true])->save();

            return $subscription->refresh();
        }

        $subscription->forceFill(['status' => 'canceled', 'cancel_at_period_end' => false])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->transitions->execute($tenant, AccountState::Cancelled, $why);
        }

        return $subscription->refresh();
    }

    /**
     * Bring a suspended account back.
     *
     * Deliberately does NOT charge: reactivation is what happens once a payment is confirmed, and a
     * method that both charged and reactivated would be one webhook away from reactivating on an
     * unconfirmed charge.
     */
    public function reactivate(Subscription $subscription, string $why): Subscription
    {
        $subscription->forceFill([
            'status' => 'active',
            'grace_ends_at' => null,
            'current_period_end' => $this->nextPeriodEnd($subscription, Carbon::now()),
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->transitions->execute($tenant, AccountState::Active, $why);
        }

        $this->audit->log(
            action: 'subscription.reactivated',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            reason: $why,
            tenantId: (string) $subscription->tenant_id,
        );

        return $subscription->refresh();
    }

    // ── Reading ───────────────────────────────────────────────────────────────────────────────

    private function nextPeriodEnd(Subscription $subscription, Carbon $from): Carbon
    {
        return $subscription->billing_interval === 'annual' ? $from->copy()->addYear() : $from->copy()->addMonth();
    }

    private function hasSettledChargeForPeriod(Subscription $subscription): bool
    {
        return SubscriptionPayment::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', 'paid')
            ->whereNull('refunded_at')
            ->where('created_at', '>=', $subscription->current_period_end ?? Carbon::now()->subYear())
            ->exists();
    }

    private function tenantOf(Subscription $subscription): ?Tenant
    {
        return Tenant::query()->withoutGlobalScopes()->find($subscription->tenant_id);
    }

    private function find(?string $id): ?Subscription
    {
        return $id === null ? null : $this->query()->whereKey($id)->first();
    }

    /** @return Builder<Subscription> */
    private function query()
    {
        // The lifecycle runs for every tenant at once, from a schedule that belongs to none of them.
        return Subscription::query()->withoutGlobalScope(TenantScope::class);
    }

    /** @return Collection<int, Subscription> */
    public function allTrialing(): Collection
    {
        return $this->query()->where('status', 'trialing')->get();
    }
}
