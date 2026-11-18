<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Enums;

/**
 * How a workspace holds a key right now (spec §1 «never break»).
 *
 * - Full: enabled by the plan.
 * - Grace: no longer in the plan, still working until `grace_ends_at`; the owner gets a heads-up before it ends.
 * - Grandfathered: no longer in the plan, kept working because the plan was set to «keep existing».
 * - ViewOnly: not in the plan; data and settings are kept and readable, nothing new is sent, edits are refused.
 * - Off: never held; nothing to show.
 */
enum EntitlementMode: string
{
    case Full = 'full';
    case Grace = 'grace';
    case Grandfathered = 'grandfathered';
    case ViewOnly = 'view_only';
    case Off = 'off';

    public function allowsUse(): bool
    {
        return in_array($this, [self::Full, self::Grace, self::Grandfathered], true);
    }
}
