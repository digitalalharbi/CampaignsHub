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
     * @return array{requires_mobile: bool, requires_approval: bool, requires_payment: bool}
     */
    public function for(RegistrationRequest $request): array
    {
        $default = (array) config('accounts.registration.default', []);
        $perPlan = (array) config('accounts.registration.plans.'.(string) $request->plan_code, []);
        // Per-account-type is the coarser lever — "every agency is reviewed" — and a plan may still
        // override it, because a plan is the more specific statement.
        $perType = (array) config('accounts.registration.account_types.'.(string) $request->account_type, []);

        $merged = array_merge($default, $perType, $perPlan);

        return [
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
