<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;

/**
 * What a workspace may see and do — the frontend renders navigation from `nav()`, the backend guards
 * routes with `allows()`, and there is never a second answer somewhere else.
 *
 * REG-001 changed what this class decides. It used to choose BETWEEN TWO MENUS from the workspace's
 * account type: a `personal` menu (dashboard, requests, clients, messaging, agency billing, team) and
 * a `company` one. That was a second portal system, competing with `Portal` and winning wherever the
 * rail was concerned — and its `personal` branch was the agency console, handed out to freelancers,
 * in-house teams, and every workspace whose account type was never set, because `personal` was also
 * the fallback. Registering without naming a type put an advertiser inside the agency's menu.
 *
 * The section catalogue now belongs to the PORTAL (`Portal::sections()`), which is the thing a section
 * is actually a part of. What remains here is narrowing: a portal offers a section, and this class
 * decides whether THIS workspace — on its plan, with its modules — currently gets it. Offering and
 * withholding are different questions, and only the second one depends on the account.
 *
 * The account type still exists and still matters. It decides which portal a new registration is
 * seeded into (`Portal::forAccountType`) and it is reported to the client for display. It no longer
 * decides what a portal looks like once you are inside it.
 */
final class AccountEntitlements
{
    /**
     * Sections that exist only when the influencer module is enabled. Kept as a narrowing rule
     * rather than a separate menu: the influencers portal offers these, and a workspace that has
     * not bought the module does not get them — the portal's shape does not change, its contents do.
     */
    private const MODULE_SECTIONS = [
        'influencer_marketing' => ['collaborations', 'roster', 'deliverables'],
    ];

    /**
     * The workspace's kind, for display and for onboarding's branching question only.
     *
     * NOT a navigation input any more. It is still consulted by the onboarding wizard, which asks a
     * multi-client workspace for its first client and a self-serve one for its first project — a
     * genuine difference in the questions asked, not in the portal handed over afterwards.
     */
    public function workspaceKind(Tenant $tenant): string
    {
        $type = $tenant->account_type;

        return $type !== null && in_array($type, AccountType::values(), true)
            ? AccountType::from($type)->workspaceKind()
            : 'personal';
    }

    /** @return list<string> enabled marketing modules (paid_media, influencer_marketing) */
    public function modules(Tenant $tenant): array
    {
        $mods = $tenant->enabled_modules;

        return is_array($mods) && $mods !== [] ? array_values($mods) : ['paid_media'];
    }

    /**
     * The sections this workspace may see IN THIS PORTAL.
     *
     * Fail-closed on a null portal. A request that has resolved no membership has no portal, and the
     * honest answer to "what may they see?" is nothing — not the previous default, which was the
     * agency menu.
     *
     * @return list<string>
     */
    public function nav(Tenant $tenant, ?Portal $portal): array
    {
        if ($portal === null) {
            return [];
        }

        $modules = $this->modules($tenant);

        $withheld = [];
        foreach (self::MODULE_SECTIONS as $module => $sections) {
            if (! in_array($module, $modules, true)) {
                $withheld = [...$withheld, ...$sections];
            }
        }

        return array_values(array_filter(
            $portal->sections(),
            static fn (string $section) => ! in_array($section, $withheld, true),
        ));
    }

    /** A module switcher only makes sense when more than one module is enabled. */
    public function showModuleSwitcher(Tenant $tenant): bool
    {
        return count($this->modules($tenant)) > 1;
    }

    /**
     * Whether this workspace, in this portal, may reach a capability (the backend guard).
     *
     * Two things must both hold: the portal has to offer the section at all, and the workspace's
     * modules must not have withheld it. The first is why an advertiser calling an agency endpoint
     * is refused — `app` does not offer `clients`, whatever the workspace has paid for.
     */
    public function allows(Tenant $tenant, string $navKey, ?Portal $portal): bool
    {
        return in_array($navKey, $this->nav($tenant, $portal), true);
    }

    /** @return array<string,mixed> the entitlements payload surfaced to the SPA on boot */
    public function toArray(Tenant $tenant, ?Portal $portal): array
    {
        return [
            'account_type' => $tenant->account_type,
            'workspace_kind' => $this->workspaceKind($tenant),
            // Which portal these entitlements describe. Present so the client cannot mistake one
            // portal's nav for another's, and so a mismatch is visible rather than silent.
            'portal' => $portal?->value,
            'enabled_modules' => $this->modules($tenant),
            'module_switcher' => $this->showModuleSwitcher($tenant),
            'nav' => $this->nav($tenant, $portal),
            'subscription_plan' => $tenant->subscription_plan,
            'onboarding' => [
                'completed' => $tenant->onboarding_completed_at !== null,
                'step' => $tenant->onboarding_step,
            ],
        ];
    }
}
