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
     * Administrative exceptions widen this, and can only widen it (GRANT-001).
     *
     * The brief asks the platform owner to be able to give one account something beyond its plan and
     * to take it back. That is a union with what the plan already allows, applied here — the one
     * place `nav()`, `allows()` and the rail all read — rather than at each of those in turn.
     *
     * It is deliberately additive. A grant cannot remove a section, so no grant, expiry or bug in
     * the grants table can take away something a customer paid for; the only thing that narrows
     * access is suspension, which preserves their data.
     */
    public function __construct(private readonly AccountGrants $grants) {}

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
        $mods = is_array($mods) && $mods !== [] ? array_values($mods) : ['paid_media'];

        // A module granted from the console counts as enabled, without editing the tenant's own
        // column — so revoking the grant restores exactly what they had, and the record of who gave
        // it away survives the revocation.
        return array_values(array_unique([...$mods, ...$this->grants->modules($tenant)]));
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

        /*
         * A full-access grant withholds nothing. It is still bounded by the PORTAL: an advertiser
         * given full access gets everything `/app` offers, not the agency's client roster, because
         * a portal a workspace does not hold is not a capability that can be granted to it.
         */
        if ($this->grants->hasFullAccess($tenant)) {
            $withheld = [];
        }

        $allowed = array_values(array_filter(
            $portal->sections(),
            static fn (string $section) => ! in_array($section, $withheld, true),
        ));

        // Individually granted sections, intersected with what this portal offers at all — for the
        // same reason: a grant widens what the workspace may reach inside its portal, and cannot
        // invent a section the portal does not have.
        $granted = array_values(array_intersect($this->grants->sections($tenant), $portal->sections()));

        return array_values(array_unique([...$allowed, ...$granted]));
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
            /*
             * What was granted rather than bought, stated as itself.
             *
             * The console needs it, and so does the customer's own billing screen: a workspace on a
             * complimentary plan must not be shown an invoice it will never receive.
             */
            'grants' => [
                'full_access' => $this->grants->hasFullAccess($tenant),
                'sections' => $this->grants->sections($tenant),
                'modules' => $this->grants->modules($tenant),
                'complimentary_plan' => $this->grants->complimentaryPlan($tenant),
            ],
            'onboarding' => [
                'completed' => $tenant->onboarding_completed_at !== null,
                'step' => $tenant->onboarding_step,
            ],
        ];
    }
}
