<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use App\Domains\Branding\Models\BrandingSetting;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;

/**
 * BRANDING-WHITE-LABEL-ENTITLEMENT — a stored preference becomes an entitlement only when the
 * subscription carries the feature.
 *
 * ## What was wrong
 *
 * `BrandingSetting.white_label` was written by the Branding Center and read straight back. The
 * model's own note said «whether white_label is actually *permitted* is a subscription concern», and
 * that concern was implemented nowhere — so ticking a box in settings was the entire gate on a paid
 * capability.
 *
 * ## No plan is named here, deliberately
 *
 * The plan catalogue already carries a `white_label` boolean per plan, and operators edit it in
 * /admin. Naming «agency» and «enterprise» in code would freeze a commercial decision into a
 * deployment: a new plan, a rename, or a promotional tier would each need a release. This asks the
 * SUBSCRIPTION what its plan grants, so the catalogue stays the place the answer lives.
 *
 * ## A lapsed subscription does not keep the feature
 *
 * Only `active` and `trialing` grant it. `past_due` and `cancelled` are subscriptions that exist and
 * do not entitle — treating «has a row» as «is entitled» would let a cancelled agency keep serving
 * unbranded client reports indefinitely, which is the exact leak this closes.
 *
 * ## The stored flag is never overwritten
 *
 * A tenant that downgrades keeps its preference and loses its effect; upgrading restores it without
 * anybody re-ticking a box. `reason()` exists so a surface can say WHY the switch is off rather than
 * showing a dead control — «hiding a menu item is NOT security», and a silent no-op is worse than a
 * refusal that explains itself.
 */
final class WhiteLabelEntitlement
{
    /** The subscription states that carry their plan's features. */
    private const GRANTING = ['active', 'trialing'];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** Whether this tenant's plan currently grants white-labelling at all. */
    public function permitted(Tenant $tenant): bool
    {
        $subscription = $this->subscriptions->subscriptionFor($tenant);

        if ($subscription === null || ! in_array($subscription->status, self::GRANTING, true)) {
            return false;
        }

        return ($subscription->plan?->features['white_label'] ?? false) === true;
    }

    /**
     * Whether white-labelling is in force: the operator asked for it AND the plan grants it.
     *
     * Both halves are required and neither implies the other — a plan that grants it does not turn
     * it on, and a tick that outlives a downgrade does not keep it on.
     */
    public function effective(Tenant $tenant, ?BrandingSetting $setting): bool
    {
        return $setting !== null && $setting->white_label === true && $this->permitted($tenant);
    }

    /**
     * Why it is not in force, or null when it is — for a surface that must explain rather than
     * silently ignore what the operator asked for.
     */
    public function reason(Tenant $tenant, ?BrandingSetting $setting): ?string
    {
        if ($this->effective($tenant, $setting)) {
            return null;
        }

        if ($setting === null || $setting->white_label !== true) {
            return 'not_requested';
        }

        $subscription = $this->subscriptions->subscriptionFor($tenant);

        return match (true) {
            $subscription === null => 'no_subscription',
            ! in_array($subscription->status, self::GRANTING, true) => 'subscription_not_active',
            default => 'plan_does_not_include_white_label',
        };
    }
}
