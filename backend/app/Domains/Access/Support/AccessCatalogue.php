<?php

declare(strict_types=1);

namespace App\Domains\Access\Support;

use App\Domains\Access\Models\Permission;

/**
 * ACCESS-CENTER-001 — the ten plain-language areas the access center shows, over the ONE permission catalogue.
 *
 * «فئات الدعوات»: the Owner decides what an invitee sees by switching whole areas on or off — المالية،
 * إدارة المشاريع، إدارة الحملات … — and may take a single area away from one invitation.
 *
 * ## What this class is, and what it is not
 *
 * It is a GROUPING of the keys `PermissionSeeder` already writes and the routes already check. It adds no key and
 * no enforcement path: a category is still a tenant `Role`, its keys are still `permission_role` rows, and the
 * request is still answered by `HasRoles::permissionKeys()` — now with the per-member overrides applied there.
 * `AccessCatalogueTest` fails the build when a catalogue key is in no area (or in two), or when an area names a
 * key the catalogue does not hold, so this list and the seeder cannot drift apart.
 *
 * ## Levels
 *
 * The catalogue already separates reading from acting (`budget.view` / `budget.manage`, `campaigns.view` /
 * `campaigns.launch` …). An area therefore has three states: `none`, `view` (its reading keys), `manage` (its
 * reading and acting keys). Setting a level writes exactly those keys; reading a level from a role reports
 * `manage` when any acting key is held, `view` when only reading keys are, with the held/total count beside it
 * so a role tuned key-by-key on the roles screen is never shown as more than it is.
 *
 * ## Outside the areas
 *
 * - `BASE` — what every member needs to open a project at all; never a switch (turning it off would leave a
 *   blank product, not a narrower one).
 * - `SCOPE` — `clients.view_all`, «كل العملاء». It is the client ceiling, set with the scope control, and it
 *   counts as held only by somebody who really reaches every client (`WorkspaceRolePresets::heldBy`).
 */
final class AccessCatalogue
{
    public const NONE = 'none';

    public const VIEW = 'view';

    public const MANAGE = 'manage';

    public const LEVELS = [self::NONE, self::VIEW, self::MANAGE];

    /** @var list<string> */
    public const BASE = ['projects.view', 'projects.view.all', 'notifications.view'];

    /** @var list<string> */
    public const SCOPE = ['clients.view_all'];

    /**
     * Keys that let a person run the team or the workspace itself. Only the workspace owner hands these out —
     * a category that carries one, or an override that adds one (contract §5 P0: nobody escalates past the owner).
     *
     * @var list<string>
     */
    public const ADMIN_KEYS = ['settings.manage', 'users.invite', 'users.update', 'users.remove', 'subscriptions.manage'];

    /**
     * @return list<array{key: string, ar: string, en: string, view: list<string>, manage: list<string>}>
     */
    public static function areas(): array
    {
        return [
            ['key' => 'projects', 'ar' => 'إدارة المشاريع', 'en' => 'Projects', 'view' => [
                'clients.view', 'workspaces.view', 'proposals.view', 'tasks.view',
            ], 'manage' => [
                'clients.create', 'clients.update', 'clients.delete', 'clients.archive', 'clients.restore',
                'clients.manage_team', 'clients.manage_settings',
                'projects.create', 'projects.update', 'projects.delete', 'projects.manage_members',
                'projects.archive', 'projects.restore', 'projects.purge', 'projects.move',
                'workspaces.create', 'workspaces.update', 'workspaces.delete', 'workspaces.members.manage',
                'proposals.create', 'proposals.update', 'proposals.approve',
                'tasks.create', 'tasks.update', 'tasks.delete',
            ]],
            ['key' => 'campaigns', 'ar' => 'إدارة الحملات', 'en' => 'Campaigns', 'view' => [
                'campaigns.view', 'alerts.view', 'automations.view', 'ai.view', 'influencers.view',
            ], 'manage' => [
                'campaigns.create', 'campaigns.update', 'campaigns.launch', 'campaigns.pause', 'campaigns.link', 'campaigns.budget.change',
                'alerts.manage', 'automations.manage', 'ai.use', 'ai.manage', 'influencers.manage', 'influencers.approve',
            ]],
            ['key' => 'content', 'ar' => 'المحتوى', 'en' => 'Content', 'view' => [
                'content.view',
            ], 'manage' => [
                'content.create', 'content.update', 'content.approve', 'content.reject',
            ]],
            ['key' => 'results', 'ar' => 'النتائج والتقارير', 'en' => 'Results & reports', 'view' => [
                'analytics.view', 'reports.view', 'clients.view_analytics', 'clients.view_reports',
            ], 'manage' => [
                'reports.create', 'reports.export', 'reports.share', 'reports.approve', 'clients.create_reports', 'clients.share_reports',
            ]],
            ['key' => 'leads', 'ar' => 'المهتمين', 'en' => 'Leads', 'view' => [
                'leads.view',
            ], 'manage' => [
                'leads.create', 'leads.update', 'leads.delete', 'leads.convert', 'leads.assign', 'leads.export', 'leads.pii.view',
            ]],
            ['key' => 'finance', 'ar' => 'المالية', 'en' => 'Finance', 'view' => [
                'budget.view', 'funding.view', 'billing.view', 'influencers.view_costs',
            ], 'manage' => [
                'budget.manage', 'funding.manage', 'billing.manage', 'billing.refund',
            ]],
            ['key' => 'requests', 'ar' => 'الطلبات والرسائل', 'en' => 'Requests & messages', 'view' => [
                'requests.view', 'messaging.view',
            ], 'manage' => [
                'requests.view_all', 'requests.update', 'requests.assign', 'requests.change_status', 'requests.change_priority',
                'requests.comment_internal', 'requests.comment_client', 'requests.request_information', 'requests.manage_files',
                'requests.manage_sla', 'requests.archive', 'requests.convert', 'messaging.manage',
            ]],
            ['key' => 'integrations', 'ar' => 'التكاملات', 'en' => 'Integrations', 'view' => [
                'integrations.view', 'connections.view',
            ], 'manage' => [
                'integrations.connect', 'integrations.disconnect', 'connections.manage',
            ]],
            ['key' => 'files', 'ar' => 'الملفات', 'en' => 'Files', 'view' => [
                'drive.view',
            ], 'manage' => [
                'drive.manage', 'clients.manage_files',
            ]],
            ['key' => 'team', 'ar' => 'الفريق والإعدادات', 'en' => 'Team & settings', 'view' => [
                'users.view', 'audit.view', 'branding.view', 'subscriptions.view', 'taxonomies.view',
            ], 'manage' => [
                'settings.manage', 'users.invite', 'users.update', 'users.remove', 'branding.manage', 'subscriptions.manage',
                'taxonomies.manage', 'options.create', 'options.update', 'options.reorder', 'options.merge', 'options.deactivate',
                'options.manage_system_labels',
            ]],
        ];
    }

    /** @return list<string> */
    public static function areaKeys(): array
    {
        return array_column(self::areas(), 'key');
    }

    /** @return list<string> every key some area switches */
    public static function switchableKeys(): array
    {
        $keys = [];
        foreach (self::areas() as $area) {
            array_push($keys, ...$area['view'], ...$area['manage']);
        }

        return array_values(array_unique($keys));
    }

    public static function areaOf(string $key): ?string
    {
        foreach (self::areas() as $area) {
            if (in_array($key, $area['view'], true) || in_array($key, $area['manage'], true)) {
                return $area['key'];
            }
        }

        return null;
    }

    /**
     * The level of every area for a set of keys.
     *
     * @param  list<string>  $keys
     * @return array<string, array{level: string, held: int, total: int}>
     */
    public static function levelsFor(array $keys): array
    {
        $out = [];
        foreach (self::areas() as $area) {
            $view = array_intersect($area['view'], $keys);
            $manage = array_intersect($area['manage'], $keys);
            $out[$area['key']] = [
                'level' => $manage !== [] ? self::MANAGE : ($view !== [] ? self::VIEW : self::NONE),
                'held' => count($view) + count($manage),
                'total' => count($area['view']) + count($area['manage']),
            ];
        }

        return $out;
    }

    /**
     * The keys after some areas are set to a level. Areas not named are left exactly as they were, and keys
     * outside every area (the base, the client scope) are never touched here.
     *
     * @param  list<string>  $keys
     * @param  array<string, string>  $levels
     * @return list<string>
     */
    public static function applyLevels(array $keys, array $levels): array
    {
        $byKey = [];
        foreach (self::areas() as $area) {
            $byKey[$area['key']] = $area;
        }

        foreach ($levels as $areaKey => $level) {
            $area = $byKey[$areaKey] ?? null;
            if ($area === null || ! in_array($level, self::LEVELS, true)) {
                continue; // validated by the caller; an unknown name never widens anything
            }
            $keys = array_values(array_diff($keys, $area['view'], $area['manage']));
            if ($level === self::VIEW || $level === self::MANAGE) {
                array_push($keys, ...$area['view']);
            }
            if ($level === self::MANAGE) {
                array_push($keys, ...$area['manage']);
            }
        }

        return self::existing(array_values(array_unique($keys)));
    }

    /** @param list<string> $keys */
    public static function isAdminSet(array $keys): bool
    {
        return array_intersect(self::ADMIN_KEYS, $keys) !== [];
    }

    /**
     * Restricted to keys the catalogue actually holds — a key the seeder never wrote cannot be granted, and must
     * not be reported as granted either.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function existing(array $keys): array
    {
        $catalogue = Permission::query()->pluck('key')->map(fn ($k) => (string) $k)->all();

        return array_values(array_intersect($keys, $catalogue));
    }
}
