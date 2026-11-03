<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Models\RegistrationRequest;

/**
 * What a given registration has to satisfy before it becomes a workspace (SIGNUP-002).
 *
 * The contract permits auto-activation ONLY where a policy is explicitly set for it, so this is the
 * thing that has to be explicit. It is deliberately data rather than branching in the registration
 * controller: "who may self-register, and what do they have to clear first?" is a question the
 * platform owner answers from `/admin`, and encoding it as conditionals would mean a deploy every
 * time the answer changed.
 *
 * The default is stated in config and is currently **email verification only**, which is what the
 * product does today. That is the auto-activate branch the contract allows, made visible: it is a
 * choice with a name, not the absence of a gate.
 *
 * Per-plan overrides live under `accounts.registration.plans.<code>`, so requiring approval for
 * agencies while leaving self-serve trials open is a config change.
 */
final class RegistrationPolicy
{
    /**
     * The platform's verification switches come from the APPLICATION'S snapshot, never the live setting, so a change at
     * `/admin` cannot move anybody already in the queue. Approval and payment have no switch here and stay as they were:
     * no verification decision can open a commercial gate. A reviewer's statement on this one application still wins.
     *
     * @return array{requires_email: bool, requires_mobile: bool, requires_approval: bool, requires_payment: bool}
     */
    public function for(RegistrationRequest $request): array
    {
        $default = (array) config('accounts.registration.default', []);
        $perPlan = (array) config('accounts.registration.plans.'.(string) $request->plan_code, []);
        // Per-account-type is the coarser lever — "every agency is reviewed" — and a plan may still
        // override it, because a plan is the more specific statement.
        $perType = (array) config('accounts.registration.account_types.'.(string) $request->account_type, []);

        /*
         * A reviewer's decision on THIS application (SIGNUP-003).
         *
         * The most specific statement there is, so it wins: waiving a payment for one customer, or
         * insisting on a review for one that a plan would have let straight through. It is stored on
         * the request as a record of what was granted and by whom, rather than applied silently to a
         * state column where the reason would be lost.
         */
        $perRequest = (array) (($request->review_concessions ?? [])['policy'] ?? []);

        $switches = (array) (($request->effective_policy ?? [])['switches'] ?? []);

        $merged = array_merge($default, $perType, $perPlan);
        // Absent means required: an application opened before the switches existed owes what it always owed.
        $merged['requires_email'] = (bool) ($merged['requires_email'] ?? true) && (bool) ($switches['email'] ?? true);
        $merged['requires_mobile'] = (bool) ($merged['requires_mobile'] ?? false) && (bool) ($switches['mobile'] ?? true);
        /*
         * A reviewer can demand a payment here, and can no longer excuse one (SIGNUP-DECISION-001). A bare `false` carries no
         * plan, period or amount, so what clears the money is a scoped decision: see `RegistrationCommercialGate`.
         */
        if (($perRequest['requires_payment'] ?? null) === false) {
            unset($perRequest['requires_payment']);
        }
        $merged = array_merge($merged, $perRequest);

        return [
            'requires_email' => (bool) $merged['requires_email'],
            'requires_mobile' => (bool) ($merged['requires_mobile'] ?? false),
            'requires_approval' => (bool) ($merged['requires_approval'] ?? false),
            'requires_payment' => (bool) ($merged['requires_payment'] ?? false),
        ];
    }

    /** True when nothing beyond email verification stands between this applicant and a workspace. */
    public function isAutoActivate(RegistrationRequest $request): bool
    {
        $policy = $this->for($request);

        return ! $policy['requires_mobile']
            && ! $policy['requires_approval']
            && ! $policy['requires_payment'];
    }
}
