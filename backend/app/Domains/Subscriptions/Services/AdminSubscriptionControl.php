<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADMIN-FULL-CONTROL-001 — the platform owner runs one workspace's subscription from the console, with no money moving.
 *
 * Nothing here opens a charge, calls a provider, writes a payment or issues an invoice. What it writes says so: an activation or
 * extension is `admin_grant`, and open-ended access is `complimentary`, so nobody can read either as paid. Each action is taken
 * under a row lock and recorded once against THIS workspace, with who, why, and the state before and after.
 *
 * Ending access is a suspension with the data intact and an administrator able to reverse it. A customer's own cancellation is
 * the terminal one, and that is deliberately not reopened from here: a closed account starts again through registration.
 */
final class AdminSubscriptionControl
{
    public const ACTIONS = ['activate', 'extend', 'end', 'complimentary', 'convert_paid', 'convert_discount', 'change_plan'];

    public const BASIS_ADMIN_GRANT = 'admin_grant';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly PlanCatalogue $catalogue,
        private readonly TransitionAccountState $transitions,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function apply(Tenant $tenant, array $input, User $actor): Subscription
    {
        return DB::transaction(function () use ($tenant, $input, $actor): Subscription {
            $action = (string) $input['action'];
            $reason = (string) $input['reason'];
            /** @var Subscription|null $current */
            $current = Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->getKey())->lockForUpdate()->first();

            $before = $this->snapshot($current, $tenant);
            if ($current !== null) {
                $current->auditReason = $reason;
            }

            $after = match ($action) {
                'activate' => $this->activate($tenant, $current, $input, $reason),
                'extend' => $this->extend($tenant, $current, $input, $reason),
                'end' => $this->end($tenant, $current, $reason),
                'complimentary' => $this->complimentary($tenant, $current, $input, $reason),
                'convert_paid' => $this->convert($tenant, $current, $input, null),
                'convert_discount' => $this->convert($tenant, $current, $input, $this->percent($input)),
                default => $this->changePlan($tenant, $current, $input),
            };

            $this->audit->log(
                action: 'platform.subscription.'.$action,
                entityType: Subscription::class,
                entityId: (string) $after->getKey(),
                before: $before,
                after: $this->snapshot($after, $tenant->refresh()),
                reason: $reason,
                userId: (int) $actor->getKey(),
                tenantId: (string) $tenant->getKey(),
            );

            return $after->refresh();
        });
    }

    /** What a next charge would be, in the plan's own currency. Null when no discount is waiting. */
    public static function nextCharge(Subscription $subscription): ?array
    {
        $percent = $subscription->next_charge_discount_percent;

        if ($percent === null || $subscription->unit_amount === null) {
            return null;
        }

        return [
            'discount_percent' => (int) $percent,
            'amount' => self::discounted((string) $subscription->unit_amount, (int) $percent),
            'currency' => $subscription->currency,
        ];
    }

    public static function discounted(string $amount, int $percent): string
    {
        return number_format(round((float) $amount * (100 - $percent) / 100, 2), 2, '.', '');
    }

    /** @param  array<string, mixed>  $input */
    private function activate(Tenant $tenant, ?Subscription $current, array $input, string $reason): Subscription
    {
        $this->refuseComplimentary($current, 'This workspace has complimentary access. End it or convert it first.');
        $this->refuseIfClosed($tenant);

        if ($current?->status === 'active' && $current->current_period_end?->isFuture()) {
            throw ValidationException::withMessages(['action' => 'This workspace is already active. Extend it instead.']);
        }

        $ends = $this->date($input);
        $plan = $this->planFrom($input, $current);
        $interval = $this->interval($input, $current);

        $subscription = $this->subscriptions->assignPlan($tenant, $plan, 'active', $ends, null, $interval);
        $subscription->auditReason = $reason;
        $subscription->forceFill([
            'current_period_start' => Carbon::now(),
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
            'commercial_basis' => self::BASIS_ADMIN_GRANT,
            'next_charge_discount_percent' => null,
        ])->save();

        $tenant->forceFill(['subscription_plan' => $plan->code])->save();
        $this->open($tenant, $reason);

        return $subscription;
    }

    /** @param  array<string, mixed>  $input */
    private function extend(Tenant $tenant, ?Subscription $current, array $input, string $reason): Subscription
    {
        $subscription = $this->requireSubscription($current);
        $this->refuseComplimentary($subscription, 'Complimentary access has no end date to extend.');
        $this->refuseIfClosed($tenant);

        if ($subscription->status === 'canceled') {
            throw ValidationException::withMessages(['action' => 'This subscription has ended. Activate it instead.']);
        }

        $ends = $this->date($input);
        if ($subscription->current_period_end !== null && $ends->lte($subscription->current_period_end)) {
            throw ValidationException::withMessages(['ends_on' => 'An extension has to end after the current date.']);
        }

        $trialing = $subscription->status === 'trialing';
        $subscription->forceFill([
            'status' => $trialing ? 'trialing' : 'active',
            'current_period_end' => $ends,
            'trial_ends_at' => $trialing ? $ends : null,
            'grace_ends_at' => null,
            // No money came with this, and the record says so. A basis already there still explains the row.
            'commercial_basis' => $subscription->commercial_basis ?? self::BASIS_ADMIN_GRANT,
        ])->save();

        $this->open($tenant, $reason);

        return $subscription;
    }

    private function end(Tenant $tenant, ?Subscription $current, string $reason): Subscription
    {
        $subscription = $this->requireSubscription($current);

        if ($subscription->status === 'canceled') {
            throw ValidationException::withMessages(['action' => 'This subscription has already ended.']);
        }

        // The ordinary suspension, which tells the people locked out and says nothing was deleted. Then the subscription ends.
        $state = $tenant->account_state ?? AccountState::Draft;
        if ($state->canTransitionTo(AccountState::Suspended)) {
            $this->lifecycle->suspend($subscription, $reason);
        } else {
            $tenant->forceFill(['status' => 'suspended'])->save();
        }

        $subscription->refresh();
        $subscription->auditReason = $reason;
        $subscription->forceFill([
            'status' => 'canceled',
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
            'commercial_basis' => null,
            'next_charge_discount_percent' => null,
            'scheduled_plan_id' => null,
            'scheduled_billing_interval' => null,
            'scheduled_unit_amount' => null,
            'scheduled_change_at' => null,
        ])->save();

        return $subscription;
    }

    /** @param  array<string, mixed>  $input */
    private function complimentary(Tenant $tenant, ?Subscription $current, array $input, string $reason): Subscription
    {
        if (($input['confirm_complimentary'] ?? false) !== true) {
            throw ValidationException::withMessages(['confirm_complimentary' => 'Direct entry with no payment has to be confirmed explicitly.']);
        }

        $this->refuseComplimentary($current, 'This workspace already has complimentary access.');
        $this->refuseIfClosed($tenant);

        $plan = $this->planFrom($input, $current);
        $subscription = $this->subscriptions->assignPlan($tenant, $plan, 'active', null, null, $this->interval($input, $current));
        $subscription->auditReason = $reason;
        $subscription->forceFill([
            'current_period_start' => Carbon::now(),
            'current_period_end' => null,
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
            'commercial_basis' => RegistrationDecision::BASIS_COMPLIMENTARY,
            'registration_decision_id' => null,
            'next_charge_discount_percent' => null,
            'scheduled_plan_id' => null,
            'scheduled_billing_interval' => null,
            'scheduled_unit_amount' => null,
            'scheduled_change_at' => null,
        ])->save();

        $tenant->forceFill(['subscription_plan' => $plan->code])->save();
        $this->open($tenant, $reason);

        return $subscription;
    }

    /**
     * From complimentary to the ordinary path: money is asked for on the date set and not a day before, by the renewal that
     * already exists. Nothing is charged here. A discount comes off that one charge and leaves the agreed price alone.
     *
     * @param  array<string, mixed>  $input
     */
    private function convert(Tenant $tenant, ?Subscription $current, array $input, ?int $percent): Subscription
    {
        $subscription = $this->requireSubscription($current);

        if (! $subscription->isComplimentary()) {
            throw ValidationException::withMessages(['action' => 'Only complimentary access is converted. Change the plan or extend instead.']);
        }

        $due = $this->date($input);
        $plan = $this->planFrom($input, $subscription);
        $interval = $this->interval($input, $subscription);

        if ($plan->priceFor($interval) === null) {
            throw ValidationException::withMessages(['interval' => 'That plan has no price on this term to charge.']);
        }

        $moved = $this->subscriptions->assignPlan($tenant, $plan, 'active', $due, null, $interval);
        $moved->auditReason = $subscription->auditReason;
        $moved->forceFill([
            'current_period_end' => $due,
            'commercial_basis' => null,
            'next_charge_discount_percent' => $percent,
        ])->save();

        $tenant->forceFill(['subscription_plan' => $plan->code])->save();

        return $moved;
    }

    /** @param  array<string, mixed>  $input */
    private function changePlan(Tenant $tenant, ?Subscription $current, array $input): Subscription
    {
        $subscription = $this->requireSubscription($current);

        if (blank($input['plan_code'] ?? null)) {
            throw ValidationException::withMessages(['plan_code' => 'Name the plan to move to.']);
        }

        $plan = $this->planFrom($input, $subscription);
        $interval = $this->interval($input, $subscription);

        if ((string) $subscription->plan_id === (string) $plan->getKey() && $subscription->billing_interval === $interval) {
            throw ValidationException::withMessages(['plan_code' => 'This workspace is already on that plan and term.']);
        }

        $reason = $subscription->auditReason;
        // The state is whatever it was: a change of plan neither opens nor closes anything, and complimentary stays complimentary.
        $moved = $this->subscriptions->assignPlan($tenant, $plan, (string) $subscription->status, null, null, $interval);
        $moved->auditReason = $reason;
        $moved->forceFill([
            'scheduled_plan_id' => null,
            'scheduled_billing_interval' => null,
            'scheduled_unit_amount' => null,
            'scheduled_change_at' => null,
        ])->save();

        $tenant->forceFill(['subscription_plan' => $plan->code])->save();

        return $moved;
    }

    /** Through the one state machine. A workspace with no recorded state keeps whatever switch it has. */
    private function open(Tenant $tenant, string $reason): void
    {
        $state = $tenant->refresh()->account_state ?? AccountState::Draft;

        if ($state !== AccountState::Active && $state->canTransitionTo(AccountState::Active)) {
            $this->transitions->execute($tenant, AccountState::Active, $reason);
        }
    }

    private function refuseIfClosed(Tenant $tenant): void
    {
        if (($tenant->account_state ?? AccountState::Draft)->isTerminal()) {
            throw ValidationException::withMessages(['action' => 'This account was closed. A closed account starts again through registration.']);
        }
    }

    private function refuseComplimentary(?Subscription $subscription, string $message): void
    {
        if ($subscription?->isComplimentary() === true) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }

    private function requireSubscription(?Subscription $subscription): Subscription
    {
        if ($subscription === null) {
            throw ValidationException::withMessages(['action' => 'This workspace has no subscription yet. Activate one or grant complimentary access.']);
        }

        return $subscription;
    }

    /** @param  array<string, mixed>  $input */
    private function planFrom(array $input, ?Subscription $current): SubscriptionPlan
    {
        $code = $input['plan_code'] ?? null;

        if (blank($code)) {
            /** @var SubscriptionPlan|null $held */
            $held = $current?->plan()->first();
            if ($held === null) {
                throw ValidationException::withMessages(['plan_code' => 'Name the plan this workspace is on.']);
            }

            return $held;
        }

        $plan = $this->catalogue->byCode((string) $code);

        if ($plan === null || ! $plan->is_active) {
            throw ValidationException::withMessages(['plan_code' => 'Unknown or inactive plan.']);
        }

        return $plan;
    }

    /** @param  array<string, mixed>  $input */
    private function interval(array $input, ?Subscription $current): string
    {
        if (! blank($input['interval'] ?? null)) {
            return (string) $input['interval'];
        }

        return $current === null ? 'monthly' : (string) ($current->billing_interval ?: 'monthly');
    }

    /** @param  array<string, mixed>  $input */
    private function date(array $input): Carbon
    {
        if (blank($input['ends_on'] ?? null)) {
            throw ValidationException::withMessages(['ends_on' => 'Choose the date.']);
        }

        $date = Carbon::parse((string) $input['ends_on'])->endOfDay();

        if ($date->isPast()) {
            throw ValidationException::withMessages(['ends_on' => 'Choose a date that has not passed.']);
        }

        return $date;
    }

    /** @param  array<string, mixed>  $input */
    private function percent(array $input): int
    {
        $percent = (int) ($input['discount_percent'] ?? 0);

        if ($percent < 1 || $percent > 99) {
            throw ValidationException::withMessages(['discount_percent' => 'A discount is between 1 and 99 percent.']);
        }

        return $percent;
    }

    /** @return array<string, mixed> */
    private function snapshot(?Subscription $subscription, Tenant $tenant): array
    {
        return [
            'status' => $subscription?->status,
            'plan_code' => $subscription?->plan()->value('code'),
            'billing_interval' => $subscription?->billing_interval,
            'unit_amount' => $subscription?->unit_amount === null ? null : (string) $subscription->unit_amount,
            'currency' => $subscription?->currency,
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'grace_ends_at' => $subscription?->grace_ends_at?->toIso8601String(),
            'commercial_basis' => $subscription?->commercial_basis,
            'next_charge_discount_percent' => $subscription?->next_charge_discount_percent,
            'tenant_status' => $tenant->status,
        ];
    }
}
