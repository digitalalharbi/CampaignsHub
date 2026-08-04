<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Notifications\SubscriptionNotifier;
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
        private readonly SubscriptionNotifier $notify,
        private readonly SubscriptionProration $proration,
    ) {}

    // ── Starting ──────────────────────────────────────────────────────────────────────────────

    /**
     * A confirmed FIRST PERIOD starts a paid subscription (PLAN-PAID-001).
     *
     * The counterpart to `beginTrial`, for the plans that are sold outright rather than trialled into
     * — which since the free tier was withdrawn includes «البداية», the plan most new customers
     * arrive on. Like `beginTrial` it is called only from `ApplySubscriptionPaymentEvent` after the
     * gateway confirmed the money, so a subscription in `active` is always one somebody paid for.
     *
     * The period end is a real term rather than a trial window: a month or a year from now, taken
     * from what was bought. No `trial_ends_at`, and no consent-to-convert timestamp — there is
     * nothing to convert from, because the customer has already bought the thing.
     */
    public function beginSubscription(Tenant $tenant, RegistrationRequest $request, SubscriptionPayment $payment): Subscription
    {
        $plan = $this->catalogue->byCode($request->plan_code);

        if ($plan === null) {
            throw new \RuntimeException('The application names no plan to subscribe to.');
        }

        $interval = (string) ($request->billing_interval ?? 'monthly');
        $periodEnd = Carbon::now()->{$interval === 'annual' ? 'addYear' : 'addMonth'}();

        $subscription = $this->subscriptions->assignPlan(
            $tenant,
            $plan,
            status: 'active',
            currentPeriodEnd: $periodEnd,
            interval: $interval,
        );

        $subscription->forceFill(['provider' => $payment->provider])->save();

        $this->tell($tenant, 'subscription_started', $subscription->refresh(), [
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'date' => $periodEnd->toDateString(),
        ]);

        $this->audit->log(
            action: 'subscription.started',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            after: ['plan' => $plan->code, 'interval' => $interval, 'current_period_end' => $periodEnd->toIso8601String()],
            tenantId: (string) $tenant->getKey(),
        );

        return $subscription->refresh();
    }

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

        $this->tell($tenant, 'trial_started', $subscription->refresh(), [
            'days' => $plan->trial_days,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'date' => $trialEnds->toDateString(),
        ]);

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
            // BEFORE the conversion, deliberately: a warning that arrives after the charge is not a
            // warning, and the whole point is that the customer can still cancel.
            'trials_warned' => $this->warnEndingTrials(),
            'trials_converted' => $this->convertDueTrials($checkout),
            // BEFORE the renewal charge: a downgrade agreed last month is meant to take effect at
            // this period's end, and charging first would bill the old price for the new period.
            'plan_changes_applied' => $this->applyDueScheduledChanges(),
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

            $this->tell($this->tenantOf($subscription), 'trial_converted', $subscription->refresh());

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

    /**
     * Trials close enough to their end that the customer should be told (PAY-003, NOTIF-SUB-001).
     *
     * The contract asks for a "قرب الانتهاء" warning, and its value is entirely in the timing: it must
     * arrive while cancelling is still free. Two days is far enough ahead to act on and near enough
     * that the trial is still fresh in mind.
     *
     * Only trials that WILL convert are warned. One with no recorded consent is going to be cancelled
     * rather than billed, and telling that customer they are about to be charged would be false.
     */
    public function warnEndingTrials(int $daysAhead = 2): int
    {
        $ending = $this->query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', Carbon::now())
            ->where('trial_ends_at', '<=', Carbon::now()->addDays($daysAhead))
            ->get()
            ->filter(fn (Subscription $s) => $s->mayAutoConvert());

        foreach ($ending as $subscription) {
            $this->tell(
                $this->tenantOf($subscription),
                'trial_ending',
                $subscription,
                [
                    'days' => (int) ceil(Carbon::now()->diffInDays($subscription->trial_ends_at, absolute: true)),
                    'date' => $subscription->trial_ends_at?->toDateString() ?? '',
                ],
                // Once per trial, not once per sweep — the warning window spans several days.
                occasion: $subscription->getKey().':'.($subscription->trial_ends_at?->toDateString() ?? ''),
            );
        }

        return $ending->count();
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

        // OPS-002: the reason was already computed and discarded. It rides to the audit observer now.
        $subscription->auditReason = $why;
        $subscription->forceFill([
            'status' => 'past_due',
            // Stamped on the row, so a customer given longer keeps it even if the default changes.
            'grace_ends_at' => $subscription->grace_ends_at ?? Carbon::now()->addDays($graceDays),
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            // PastDue is OPERATIONAL — the account keeps working while the customer sorts the card out.
            $this->transitions->execute($tenant, AccountState::PastDue, $why);

            /*
             * Told once per GRACE PERIOD, not once per sweep.
             *
             * The sweep runs daily and is safe to run twice; without the occasion in the key a
             * customer would receive "your card was refused" every morning until they fixed it.
             */
            $this->tell($tenant, 'past_due', $subscription->refresh(), [
                'date' => $subscription->grace_ends_at?->toDateString() ?? '',
            ], occasion: $subscription->getKey().':'.($subscription->grace_ends_at?->toDateString() ?? 'grace'));
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
        $subscription->auditReason = $why;
        $subscription->forceFill(['status' => 'suspended'])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->transitions->execute($tenant, AccountState::Suspended, $why);
            // Says explicitly that nothing was deleted — the first question anybody locked out asks.
            $this->tell($tenant, 'suspended', $subscription->refresh());
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
            // The START moves with the end. Proration is a fraction of a period, and a period whose
            // start never advances makes every later change look as though almost none of it is left.
            'current_period_start' => Carbon::now(),
            'current_period_end' => $this->nextPeriodEnd($subscription, Carbon::now()),
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->transitions->execute($tenant, AccountState::Active, 'A renewal was confirmed by the gateway.');

            $this->tell($tenant, 'payment_confirmed', $subscription->refresh(), [
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
            ], occasion: $subscription->getKey().':'.$payment->getKey());
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
            $tenant = $this->tenantOf($subscription);

            if ($tenant !== null) {
                $this->tell($tenant, 'renewal_failed', $subscription, [
                    'amount' => (string) $payment->amount,
                    'currency' => $payment->currency,
                ], occasion: $subscription->getKey().':'.$payment->getKey());
            }

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
        $subscription->auditReason = $why;

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
        $subscription->auditReason = $why;
        $subscription->forceFill([
            'status' => 'active',
            'grace_ends_at' => null,
            'current_period_start' => Carbon::now(),
            'current_period_end' => $this->nextPeriodEnd($subscription, Carbon::now()),
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->transitions->execute($tenant, AccountState::Active, $why);
        }

        if ($tenant !== null) {
            $this->tell($tenant, 'reactivated', $subscription->refresh());
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

    // ── Changing plan mid-term (PAY-002) ──────────────────────────────────────────────────────

    /**
     * Move a subscription to another plan, part-way through a period it has already paid for.
     *
     * Two outcomes, and which one you get is decided by the prices rather than by what the customer
     * calls it:
     *
     * - **Upgrade.** The unused part of the current period is credited against the new plan's
     *   prorated price, and only the DIFFERENCE is charged. The plan itself does not move here — a
     *   charge is opened and {@see planChangePaid()} applies it when the gateway confirms, exactly
     *   as every other activation in this application works.
     * - **Downgrade.** Nothing is charged and nothing is refunded; the change is recorded and takes
     *   effect when the period ends. The customer keeps what they paid for. Applying it immediately
     *   would take away capability that has been bought and keep the money for it, which is the one
     *   thing a billing system must never quietly do.
     *
     * A lateral move — same price, different interval, a renamed plan — applies at once, because
     * nothing is owed in either direction.
     *
     * @return array{quote: array<string, mixed>, subscription: Subscription, charge: ?array<string, mixed>}
     */
    public function changePlan(
        Subscription $subscription,
        SubscriptionPlan $newPlan,
        string $interval,
        ?SubscriptionCheckout $checkout = null,
    ): array {
        if (! in_array($interval, ['monthly', 'annual'], true)) {
            throw new \InvalidArgumentException('A subscription is billed monthly or annually.');
        }

        if ($newPlan->priceFor($interval) === null) {
            throw new \RuntimeException('That plan is not sold on that term.');
        }

        if ($subscription->plan_id === $newPlan->getKey() && $subscription->billing_interval === $interval) {
            throw new \RuntimeException('This subscription is already on that plan and term.');
        }

        $quote = $this->proration->quote($subscription, $newPlan, $interval);

        $this->audit->log(
            action: 'subscription.plan_change.requested',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            before: ['plan' => $subscription->plan?->code, 'interval' => $subscription->billing_interval],
            after: ['plan' => $newPlan->code, 'interval' => $interval] + $quote,
            tenantId: (string) $subscription->tenant_id,
        );

        if ($quote['effective'] === 'period_end') {
            return [
                'quote' => $quote,
                'subscription' => $this->scheduleChange($subscription, $newPlan, $interval, $quote),
                'charge' => null,
            ];
        }

        /*
         * An immediate change with nothing to pay is applied here.
         *
         * A lateral move, or an upgrade taken on the last day of a period where the prorated
         * difference rounds to zero. Opening a charge for 0.00 would leave a customer at a payment
         * page asking them for nothing.
         */
        if ((float) $quote['due_now'] <= 0) {
            return [
                'quote' => $quote,
                'subscription' => $this->applyPlan($subscription, $newPlan, $interval, 'The change was owed nothing.'),
                'charge' => null,
            ];
        }

        $charge = ($checkout ?? app(SubscriptionCheckout::class))->chargePlanChange(
            $subscription,
            $newPlan,
            $interval,
            (string) $quote['due_now'],
        );

        /*
         * Recorded as SCHEDULED even though it is meant to be immediate.
         *
         * Between opening the charge and the gateway confirming it, the customer has chosen a plan
         * they have not paid for. Writing it here means the interface can say «awaiting payment»
         * with the plan named, and — the part that matters — it is written to the `scheduled_*`
         * columns, which grant nothing. `plan_id` still names what they are actually entitled to.
         */
        $this->scheduleChange($subscription, $newPlan, $interval, $quote, awaitingPayment: true);

        return ['quote' => $quote, 'subscription' => $subscription->refresh(), 'charge' => $charge];
    }

    /**
     * A confirmed plan-change payment applies the plan. The single place an upgrade takes effect.
     *
     * Reached only from `ApplySubscriptionPaymentEvent`, which is reached only from a verified
     * webhook. There is deliberately no other way to move a subscription up a tier.
     */
    public function planChangePaid(SubscriptionPayment $payment): void
    {
        $subscription = $this->find($payment->subscription_id);
        $plan = $this->catalogue->byCode($payment->plan_code);

        if ($subscription === null || $plan === null) {
            return;
        }

        $this->applyPlan(
            $subscription,
            $plan,
            (string) ($payment->billing_interval ?: $subscription->billing_interval),
            'The gateway confirmed the difference for a mid-term upgrade.',
            paidAmount: (string) $payment->amount,
        );
    }

    /**
     * Apply a scheduled change once the period it was waiting for has ended.
     *
     * Called from the daily sweep and from the renewal path, so a downgrade lands whether the
     * customer renews on time or the scheduler gets there first.
     */
    public function applyDueScheduledChanges(?Carbon $now = null): int
    {
        $now = $now ?? Carbon::now();
        $applied = 0;

        $due = $this->query()
            ->whereNotNull('scheduled_plan_id')
            ->whereNotNull('scheduled_change_at')
            ->where('scheduled_change_at', '<=', $now)
            ->get();

        foreach ($due as $subscription) {
            $plan = SubscriptionPlan::query()->find($subscription->scheduled_plan_id);

            if ($plan === null) {
                // The plan was deleted between agreeing the change and applying it. Clear the
                // booking rather than leave a subscription pointing at nothing forever.
                $this->clearScheduledChange($subscription);

                continue;
            }

            $this->applyPlan(
                $subscription,
                $plan,
                (string) ($subscription->scheduled_billing_interval ?: $subscription->billing_interval),
                'A scheduled plan change reached the end of the period it was waiting for.',
            );

            $applied++;
        }

        return $applied;
    }

    /** Withdraw a change that has not taken effect yet. */
    public function cancelScheduledChange(Subscription $subscription, string $why): Subscription
    {
        if (! $subscription->hasScheduledChange()) {
            return $subscription;
        }

        $this->audit->log(
            action: 'subscription.plan_change.cancelled',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            before: ['plan' => $subscription->scheduledPlan?->code],
            reason: $why,
            tenantId: (string) $subscription->tenant_id,
        );

        return $this->clearScheduledChange($subscription);
    }

    private function scheduleChange(
        Subscription $subscription,
        SubscriptionPlan $plan,
        string $interval,
        array $quote,
        bool $awaitingPayment = false,
    ): Subscription {
        $subscription->forceFill([
            'scheduled_plan_id' => $plan->getKey(),
            'scheduled_billing_interval' => $interval,
            'scheduled_unit_amount' => $quote['new_period_price'],
            // An upgrade waiting on payment has no date: it lands when the money does, not on a
            // clock. A downgrade lands at the end of the period already paid for.
            'scheduled_change_at' => $awaitingPayment ? null : $subscription->current_period_end,
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null && ! $awaitingPayment) {
            $this->tell($tenant, 'plan_change_scheduled', $subscription->refresh(), [
                'plan_name' => $plan->name,
                'effective_at' => (string) $subscription->current_period_end?->toDateString(),
            ], occasion: $subscription->getKey().':'.$plan->getKey().':'.$interval);
        }

        return $subscription->refresh();
    }

    private function clearScheduledChange(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'scheduled_plan_id' => null,
            'scheduled_billing_interval' => null,
            'scheduled_unit_amount' => null,
            'scheduled_change_at' => null,
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Move the subscription onto the plan, for real.
     *
     * `unit_amount` becomes the new plan's price for the chosen term: it is what this customer is
     * now sold, and every later renewal reads it rather than the catalogue — so a price edited in
     * /admin tomorrow does not re-price them.
     */
    private function applyPlan(
        Subscription $subscription,
        SubscriptionPlan $plan,
        string $interval,
        string $why,
        ?string $paidAmount = null,
    ): Subscription {
        $before = ['plan' => $subscription->plan?->code, 'interval' => $subscription->billing_interval];

        $subscription->forceFill([
            'plan_id' => $plan->getKey(),
            'billing_interval' => $interval,
            'unit_amount' => $plan->priceFor($interval),
            'scheduled_plan_id' => null,
            'scheduled_billing_interval' => null,
            'scheduled_unit_amount' => null,
            'scheduled_change_at' => null,
        ])->save();

        $tenant = $this->tenantOf($subscription);

        if ($tenant !== null) {
            $this->tell($tenant, 'plan_changed', $subscription->refresh(), [
                'plan_name' => $plan->name,
                'amount' => $paidAmount ?? '0.00',
            ], occasion: $subscription->getKey().':'.$plan->getKey().':'.$interval);
        }

        $this->audit->log(
            action: 'subscription.plan_changed',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            before: $before,
            after: ['plan' => $plan->code, 'interval' => $interval, 'paid' => $paidAmount],
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

    /**
     * Raise a lifecycle message, never letting it break the transition it accompanies.
     *
     * A notifier that throws would roll back a suspension, and an account left running because an
     * email template was wrong is a worse failure than a message nobody received. The row and the
     * queue are the durable part; this call is not.
     */
    private function tell(?Tenant $tenant, string $event, Subscription $subscription, array $extra = [], ?string $occasion = null): void
    {
        if ($tenant === null) {
            return;
        }

        try {
            $this->notify->notifyTenant(
                $tenant,
                $event,
                $this->notify->contextFor($subscription, $extra),
                $occasion,
            );
        } catch (\Throwable $e) {
            report($e);
        }
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
