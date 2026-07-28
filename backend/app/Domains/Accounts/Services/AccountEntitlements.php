<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Tenancy\Models\Tenant;

/**
 * The single source of truth for what a workspace can see and do, derived from account_type + enabled_modules.
 * The frontend renders navigation from `nav()`; the backend guards routes with `allows()` — never two systems.
 */
final class AccountEntitlements
{
    // Operations Console (personal) menu. Canonical modules only — Integrations (connections) absorbs the
    // Connection Center + Drive connector; Branding lives inside Settings; Finance = 'billing' (المالية).
    private const PERSONAL_NAV = ['dashboard', 'requests', 'clients', 'projects', 'campaigns', 'analytics', 'reports', 'connections', 'alerts', 'messaging', 'billing', 'team', 'settings'];

    // SaaS Workspace (company) menu — subscriber surfaces only. Finance surfaces as 'subscriptions' (الاشتراك);
    // no agency tools (clients/requests), no internal messaging inbox, no platform settings.
    private const COMPANY_NAV = ['dashboard', 'projects', 'campaigns', 'analytics', 'reports', 'connections', 'alerts', 'subscriptions', 'team', 'settings'];

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

    /** @return list<string> the navigation keys this workspace may see */
    public function nav(Tenant $tenant): array
    {
        return $this->workspaceKind($tenant) === 'company' ? self::COMPANY_NAV : self::PERSONAL_NAV;
    }

    /** A module switcher only makes sense when more than one module is enabled. */
    public function showModuleSwitcher(Tenant $tenant): bool
    {
        return count($this->modules($tenant)) > 1;
    }

    /** Whether a given navigation key / capability is allowed for this workspace (backend guard). */
    public function allows(Tenant $tenant, string $navKey): bool
    {
        return in_array($navKey, $this->nav($tenant), true);
    }

    /** @return array<string,mixed> the entitlements payload surfaced to the SPA on boot */
    public function toArray(Tenant $tenant): array
    {
        return [
            'account_type' => $tenant->account_type,
            'workspace_kind' => $this->workspaceKind($tenant),
            'enabled_modules' => $this->modules($tenant),
            'module_switcher' => $this->showModuleSwitcher($tenant),
            'nav' => $this->nav($tenant),
            'subscription_plan' => $tenant->subscription_plan,
            'onboarding' => [
                'completed' => $tenant->onboarding_completed_at !== null,
                'step' => $tenant->onboarding_step,
            ],
        ];
    }
}
