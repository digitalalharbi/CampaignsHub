<?php

declare(strict_types=1);

namespace App\Domains\Access\Support;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ACCESS-SECTIONS-001 — the seven sections the Owner switches per member (Nov overhaul, task 2):
 * الحملات، الميزانيات، حدود الإنفاق، الفواتير، التقارير، التكاملات، الفريق.
 *
 * ## What a section is
 *
 * The third layer over the role (a tenant `Role`, layer one) and the client ceiling (membership scopes, layer two).
 * It is resolved by the SAME function as everything else — `HasRoles::permissionKeys()` and the member's rows in
 * `member_access_overrides` (`MemberOverrideMemo`) — so there is still one resolution point and one store of
 * per-person switches:
 *
 * - **held by role** — the person's effective keys include at least one key that already opens the section's API
 *   (`opens()`: the keys the routes check, and every tenant key `ProjectAbilities` turns into the section's project
 *   capability). Nobody's current reach is narrowed by this definition: it is exactly what the routes accept.
 * - **switched off** — a `deny` row `section:<key>` for this member in this tenant. `EnforceSectionAccess` refuses
 *   every route of that section with 403 on the very next request, whatever the keys say (budgets are readable
 *   through `analytics.view`, so a key deny alone could never close that section — the gate can).
 * - **switched on** where the role lacks it — `allow` rows for the section's reading key (`grant`), written by the
 *   access center under the same grant-only-what-you-hold rule as every other key.
 *
 * Effective sections = held by role AND not switched off. That list is what the navigation draws
 * (`GET /api/v1/me/access`) — a section the person lacks is hidden, not shown disabled.
 *
 * ## Deny-by-default
 *
 * The gate adds refusals and never grants: a route still answers only after its own check
 * (`project.can:…`, `hasPermission`). A role without a section's keys is refused by those checks, as before; a
 * switched-off section is refused by the gate even when the keys would pass.
 */
final class AccessSections
{
    public const PREFIX = 'section:';

    /**
     * @return list<array{key: string, ar: string, en: string, keys: list<string>, caps: list<string>, grant: list<string>, routes: list<string>}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'campaigns', 'ar' => 'الحملات', 'en' => 'Campaigns',
                'keys' => ['campaigns.view', 'campaigns.create', 'campaigns.update', 'campaigns.launch', 'campaigns.pause', 'campaigns.link', 'campaigns.budget.change'],
                'caps' => [ProjectCapability::CAMPAIGNS_VIEW, ProjectCapability::CAMPAIGNS_MANAGE],
                'grant' => ['campaigns.view'],
                'routes' => ['api.v1.projects.campaigns.*']],
            ['key' => 'budgets', 'ar' => 'الميزانيات', 'en' => 'Budgets',
                'keys' => ['budget.view', 'budget.manage', 'funding.view', 'funding.manage'],
                'caps' => [ProjectCapability::BUDGET_VIEW, ProjectCapability::BUDGET_MANAGE],
                'grant' => ['budget.view'],
                'routes' => ['api.v1.funding.*', 'api.v1.agency.client-budgets', 'api.v1.projects.scoped.metrics.budget*']],
            ['key' => 'spend_limits', 'ar' => 'حدود الإنفاق', 'en' => 'Spend limits',
                'keys' => ['budget.view', 'budget.manage'],
                'caps' => [ProjectCapability::BUDGET_VIEW, ProjectCapability::BUDGET_MANAGE],
                'grant' => ['budget.view'],
                'routes' => ['api.v1.projects.scoped.spend-limits.*']],
            ['key' => 'invoices', 'ar' => 'الفواتير', 'en' => 'Invoices',
                'keys' => ['billing.view', 'billing.manage', 'billing.refund'],
                'caps' => [],
                'grant' => ['billing.view'],
                'routes' => ['api.v1.billing.*']],
            ['key' => 'reports', 'ar' => 'التقارير', 'en' => 'Reports',
                'keys' => ['reports.view', 'reports.create', 'reports.export', 'reports.share', 'reports.approve', 'clients.view_reports', 'clients.create_reports', 'clients.share_reports'],
                'caps' => [ProjectCapability::REPORTS_VIEW, ProjectCapability::REPORTS_MANAGE],
                'grant' => ['reports.view'],
                'routes' => ['api.v1.projects.scoped.reports.*', 'api.v1.projects.scoped.report-*', 'api.v1.app.clients.reports.*']],
            ['key' => 'integrations', 'ar' => 'التكاملات', 'en' => 'Integrations',
                'keys' => ['integrations.view', 'integrations.connect', 'integrations.disconnect', 'connections.view', 'connections.manage'],
                'caps' => [ProjectCapability::INTEGRATIONS_MANAGE],
                'grant' => ['integrations.view'],
                'routes' => ['api.v1.integrations.*', 'api.v1.projects.integrations.*']],
            ['key' => 'team', 'ar' => 'الفريق', 'en' => 'Team',
                'keys' => ['users.view', 'users.invite', 'users.update', 'users.remove', 'settings.manage'],
                'caps' => [ProjectCapability::TEAM_MANAGE],
                'grant' => ['users.view'],
                'routes' => ['api.v1.settings.team.*', 'api.v1.settings.access.*', 'api.v1.settings.roles.*', 'api.v1.agency.team.*', 'api.v1.app.team.*', 'api.v1.projects.scoped.team.*']],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function isSection(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** @return array{key: string, ar: string, en: string, keys: list<string>, caps: list<string>, grant: list<string>, routes: list<string>}|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Every tenant key that already opens the section's API today: its own keys, every key `ProjectAbilities` turns
     * into one of its project capabilities, and `settings.manage` (which `ProjectAbilities` turns into all of them).
     *
     * @return list<string>
     */
    public static function opens(string $key): array
    {
        $section = self::find($key);
        if ($section === null) {
            return [];
        }
        $keys = $section['keys'];
        if ($section['caps'] !== []) {
            array_push($keys, 'settings.manage', ...ProjectAbilities::tenantKeysGranting($section['caps']));
        }

        return array_values(array_unique($keys));
    }

    /** The section a named route belongs to, or null for a route outside all seven. */
    public static function forRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }
        foreach (self::all() as $section) {
            foreach ($section['routes'] as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return $section['key'];
                }
            }
        }

        return null;
    }

    /** @param list<string> $keys */
    public static function heldByKeys(string $section, array $keys): bool
    {
        return array_intersect(self::opens($section), $keys) !== [];
    }

    /**
     * The sections switched off in a list of override keys (`section:<key>` deny rows).
     *
     * @param  iterable<string>  $denyKeys
     * @return list<string>
     */
    public static function offIn(iterable $denyKeys): array
    {
        $off = [];
        foreach ($denyKeys as $k) {
            $k = (string) $k;
            if (str_starts_with($k, self::PREFIX) && self::isSection(substr($k, strlen(self::PREFIX)))) {
                $off[] = substr($k, strlen(self::PREFIX));
            }
        }

        return array_values(array_intersect(self::keys(), $off));
    }

    /** @return list<string> sections switched off for this person in the bound tenant (or, with none bound, anywhere) */
    public static function switchedOff(User $user): array
    {
        if ($user->getKey() === null) {
            return [];
        }
        $rows = app(MemberOverrideMemo::class)->for((int) $user->getKey(), app(TenantContext::class)->tenantId());

        return self::offIn($rows->where('effect', 'deny')->pluck('permission_key')->all());
    }

    /**
     * The effective sections: held by role (or reachable through a project membership, which `ProjectAbilities`
     * answers per project) and not switched off. A platform operator holds all seven.
     *
     * @return list<string>
     */
    public static function effective(User $user): array
    {
        if ($user->is_platform_admin) {
            return self::keys();
        }
        $keys = $user->permissionKeys()->map(fn ($k) => (string) $k)->all();
        $off = self::switchedOff($user);
        $tenantId = app(TenantContext::class)->tenantId();
        $projectMember = $tenantId !== null && DB::table('project_memberships')
            ->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->where('status', 'active')->exists();

        $out = [];
        foreach (self::all() as $section) {
            if (in_array($section['key'], $off, true)) {
                continue;
            }
            if (self::heldByKeys($section['key'], $keys) || ($projectMember && $section['caps'] !== [])) {
                $out[] = $section['key'];
            }
        }

        return $out;
    }

    /**
     * Apply a {section: on/off} choice to a person's overrides against the keys their category (and areas) give.
     *
     * - off → a `section:<key>` deny row (keys untouched — another section may share them).
     * - on → the deny row goes; when nothing they hold opens it, its reading key is allowed.
     * Sections not named are left exactly as they were.
     *
     * @param  list<string>  $baseKeys  keys after the category and the area choices
     * @param  array{allow: list<string>, deny: list<string>}  $overrides
     * @param  array<string, bool>  $sections
     * @return array{allow: list<string>, deny: list<string>, effective: list<string>}
     */
    public static function apply(array $baseKeys, array $overrides, array $sections): array
    {
        $allow = $overrides['allow'];
        $deny = $overrides['deny'];
        foreach ($sections as $key => $on) {
            $section = self::find((string) $key);
            if ($section === null) {
                continue; // validated by the caller; an unknown name never widens anything
            }
            $marker = self::PREFIX.$section['key'];
            $deny = array_values(array_diff($deny, [$marker]));
            if (! $on) {
                $deny[] = $marker;

                continue;
            }
            $effective = array_values(array_diff(array_unique([...$baseKeys, ...$allow]), $deny));
            if (! self::heldByKeys($section['key'], $effective)) {
                $grant = AccessCatalogue::existing($section['grant']);
                $allow = array_values(array_unique([...$allow, ...$grant]));
                $deny = array_values(array_diff($deny, $grant));
            }
        }

        $effective = array_values(array_diff(array_unique([...$baseKeys, ...$allow]), $deny));

        return ['allow' => array_values(array_unique($allow)), 'deny' => array_values(array_unique($deny)), 'effective' => $effective];
    }

    /**
     * The section states for a set of effective keys plus section deny markers.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $off
     * @return array<string, bool>
     */
    public static function statesFor(array $keys, array $off): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = ! in_array($key, $off, true) && self::heldByKeys($key, $keys);
        }

        return $out;
    }
}
