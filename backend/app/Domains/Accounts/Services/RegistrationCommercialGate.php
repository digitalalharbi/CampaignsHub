<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What, if anything, satisfies the payment an application owes (SIGNUP-DECISION-001).
 *
 * One answer for `AdvanceRegistration` and `ProvisionWorkspace`, so neither holds its own opinion. Approval alone is never
 * an answer. Each decision is checked against what it was actually scoped to: a waiver for another plan, or one whose
 * period has passed, clears nothing.
 */
final class RegistrationCommercialGate
{
    public const SETTLED = 'settled_payment';

    public const EXISTING_SUBSCRIPTION = 'existing_subscription';

    public function inForce(RegistrationRequest $request): ?RegistrationDecision
    {
        return RegistrationDecision::query()
            ->where('registration_request_id', $request->getKey())
            ->where('status', RegistrationDecision::IN_FORCE)
            ->first();
    }

    /** The name of whatever clears the payment gate, or null when the money is still owed. */
    public function clearedBy(RegistrationRequest $request): ?string
    {
        $decision = $this->inForce($request);

        if ($this->hasSettledPayment($request, $decision)) {
            return self::SETTLED;
        }

        if ($decision === null) {
            return null;
        }

        return match ($decision->outcome) {
            RegistrationDecision::WAIVER => $this->waiverHolds($request, $decision) ? RegistrationDecision::BASIS_WAIVED : null,
            RegistrationDecision::TRIAL => $this->trialHolds($request, $decision) ? RegistrationDecision::BASIS_TRIAL : null,
            RegistrationDecision::EXTERNAL_SETTLEMENT => $this->settlementHolds($request, $decision) ? RegistrationDecision::BASIS_EXTERNAL : null,
            RegistrationDecision::JOIN_EXISTING => $this->joinHolds($decision) ? self::EXISTING_SUBSCRIPTION : null,
            RegistrationDecision::COMPLIMENTARY => $this->complimentaryHolds($request, $decision) ? RegistrationDecision::BASIS_COMPLIMENTARY : null,
            default => null,
        };
    }

    /**
     * Paid and never reversed. With a discount in force, at least the exact remainder in the agreed currency: a charge
     * for less than was agreed has not settled what is owed.
     */
    public function hasSettledPayment(RegistrationRequest $request, ?RegistrationDecision $decision = null): bool
    {
        $settled = SubscriptionPayment::query()
            ->where('registration_request_id', $request->getKey())
            ->where('status', 'paid')
            ->whereNull('refunded_at')
            ->get();

        if ($decision?->outcome !== RegistrationDecision::DISCOUNT) {
            return $settled->isNotEmpty();
        }

        $terms = (array) $decision->terms;

        return $settled->contains(fn (SubscriptionPayment $payment): bool => $payment->currency === ($terms['currency'] ?? null)
            && (float) $payment->amount >= (float) ($terms['amount_due'] ?? INF));
    }

    /** The percentage a discount in force takes off the first charge, or null. Only for this plan and term. */
    public function discountInForce(RegistrationRequest $request): ?RegistrationDecision
    {
        $decision = $this->inForce($request);

        return $decision?->outcome === RegistrationDecision::DISCOUNT && $this->samePurchase($request, $decision) ? $decision : null;
    }

    private function samePurchase(RegistrationRequest $request, RegistrationDecision $decision): bool
    {
        $terms = (array) $decision->terms;

        return ($terms['plan_code'] ?? null) === $request->plan_code
            && ($terms['interval'] ?? null) === (string) ($request->billing_interval ?? 'monthly');
    }

    private function decidedByAnAdministrator(RegistrationDecision $decision): bool
    {
        return $decision->actor_id !== null && User::query()->whereKey($decision->actor_id)->where('is_platform_admin', true)->exists();
    }

    private function waiverHolds(RegistrationRequest $request, RegistrationDecision $decision): bool
    {
        $terms = (array) $decision->terms;

        return ($terms['scope'] ?? null) === 'one_period'
            && $this->samePurchase($request, $decision)
            && isset($terms['fee'], $terms['period_end'])
            && Carbon::parse((string) $terms['period_end'])->isFuture()
            && $this->decidedByAnAdministrator($decision);
    }

    private function trialHolds(RegistrationRequest $request, RegistrationDecision $decision): bool
    {
        $terms = (array) $decision->terms;

        return $this->samePurchase($request, $decision)
            && isset($terms['trial_ends_at'])
            && Carbon::parse((string) $terms['trial_ends_at'])->isFuture()
            && $this->decidedByAnAdministrator($decision);
    }

    /** No date to check, so what it says and who said it are all there is: open-ended, this purchase, an administrator. */
    private function complimentaryHolds(RegistrationRequest $request, RegistrationDecision $decision): bool
    {
        $terms = (array) $decision->terms;

        return ($terms['scope'] ?? null) === 'open_ended'
            && ($terms['complimentary'] ?? null) === true
            && $this->samePurchase($request, $decision)
            && $this->decidedByAnAdministrator($decision);
    }

    private function settlementHolds(RegistrationRequest $request, RegistrationDecision $decision): bool
    {
        return $this->samePurchase($request, $decision)
            && $decision->verified_by !== null
            && $decision->verified_at !== null
            && (int) $decision->verified_by !== (int) $decision->actor_id
            && User::query()->whereKey($decision->verified_by)->where('is_platform_admin', true)->exists();
    }

    /** Joining owes no fee only while the workspace joined is itself in good standing. */
    private function joinHolds(RegistrationDecision $decision): bool
    {
        $tenantId = ((array) $decision->access)['tenant_id'] ?? null;

        if ($tenantId === null || ! $this->decidedByAnAdministrator($decision)) {
            return false;
        }

        return $this->tenantInGoodStanding((string) $tenantId);
    }

    public function tenantInGoodStanding(string $tenantId): bool
    {
        return Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->where('status', 'active')->exists()
            && Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->whereIn('status', ['active', 'trialing'])->exists();
    }
}
