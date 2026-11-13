<?php

declare(strict_types=1);

namespace App\Domains\Access\Support;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;

/**
 * TEAM-ROLE-PRESETS-001 — the ready-made workspace roles, and the checkboxes that edit them
 * (Owner, MASTER_FINAL Appendix A task 7).
 *
 * «أدوار جاهزة قابلة للتعديل بمربعات اختيار (أي صفحة تظهر وأي إجراء مسموح)».
 *
 * Every preset is a set of keys from the EXISTING permission catalogue (`PermissionSeeder`). Nothing
 * here invents an enforcement path: the routes already check these keys (`hasPermission`, the
 * `project.can:` middleware through `ProjectAbilities::FROM_TENANT`, and the client ceiling through
 * `ClientScopeResolver::ALL_CLIENTS`). A preset only decides which keys a new role starts with.
 *
 * ## Three rules the presets keep
 *
 * 1. **A client-scoped preset never holds `clients.view_all`.** «مدير مشروع: عملاؤه فقط في كل
 *    مكان» is the existing ceiling: without that key a member reaches only the clients named on
 *    their membership, which the team page grants one at a time. A brand-new member therefore
 *    reaches NOTHING until a client is chosen for them.
 * 2. **Finance reads money, not campaigns.** No `campaigns.*`, no `content.*`, no `settings.manage`,
 *    no `analytics.view` (whose project capabilities include the analytics pages). The home page is
 *    still readable: `ProjectAbilities::fromTenant` always adds the dashboard.
 * 3. **The workspace manager is not editable.** It holds every key, because somebody has to be able
 *    to grant the rest, and a checkbox that could strip it would lock the workspace out of itself.
 *
 * ## What the checkboxes may touch
 *
 * Only the keys listed in `sections()`. A key a preset needs that is not a decision anybody makes on
 * this screen (`projects.view.all`, which `ProjectAbilities::fromTenant` requires before any tenant
 * permission counts on a project) is kept as it is when a role is saved. `settings.manage` is
 * offered, and is marked as granting everything inside the reachable clients, because that is what
 * `ProjectAbilities` does with it.
 */
final class WorkspaceRolePresets
{
    public const OWNER = 'tenant-owner';

    public const PROJECT_MANAGER = 'project-manager';

    public const FINANCE = 'finance';

    public const MEDIA_BUYER = 'media-buyer';

    public const ANALYST = 'analyst';

    public const REPORTER = 'reporter';

    public const VIEWER = 'viewer';

    /** The order the screen shows them in. */
    public const ORDER = [self::OWNER, self::PROJECT_MANAGER, self::FINANCE, self::MEDIA_BUYER, self::ANALYST, self::REPORTER, self::VIEWER];

    /**
     * What every non-owner preset needs to read a project at all. `projects.view.all` is not a page
     * and is not offered as a checkbox: on its own it grants nothing (`fromTenant` then reads the
     * other keys), and the client ceiling still bounds which projects it reaches.
     */
    private const BASE = ['projects.view', 'projects.view.all', 'notifications.view'];

    /**
     * @return array<string, array{ar: string, en: string, permissions: list<string>|null}>
     *                                                                                      `null` permissions = every key in the catalogue
     */
    public static function all(): array
    {
        $read = ['clients.view', 'analytics.view', 'campaigns.view', 'reports.view'];

        return [
            self::OWNER => ['ar' => 'مدير المساحة', 'en' => 'Workspace manager', 'permissions' => null],

            self::PROJECT_MANAGER => ['ar' => 'مدير مشروع', 'en' => 'Project manager', 'permissions' => [
                ...self::BASE, ...$read,
                'clients.update', 'clients.view_analytics', 'clients.view_reports', 'clients.create_reports', 'clients.share_reports',
                'campaigns.create', 'campaigns.update', 'campaigns.launch', 'campaigns.pause',
                'content.view', 'content.create', 'content.update', 'content.approve', 'content.reject',
                'reports.create', 'reports.export', 'reports.share',
                'leads.view', 'budget.view', 'alerts.view',
                'requests.view', 'requests.update', 'requests.change_status', 'requests.comment_client', 'requests.comment_internal',
                'tasks.view', 'tasks.create', 'tasks.update',
                'messaging.view', 'messaging.manage',
            ]],

            /*
             * «الرئيسية للقراءة + قسم المالية لكل العملاء. يضيف الميزانيات. لا يرى الحملات والمحتوى
             * والإعدادات.» — every client (`clients.view_all`), the money, nothing else.
             */
            self::FINANCE => ['ar' => 'المالية', 'en' => 'Finance', 'permissions' => [
                ...self::BASE,
                'clients.view', 'clients.view_all',
                'budget.view', 'budget.manage', 'billing.view', 'billing.manage',
            ]],

            self::MEDIA_BUYER => ['ar' => 'مشتري إعلانات', 'en' => 'Media buyer', 'permissions' => [
                ...self::BASE, ...$read,
                'campaigns.create', 'campaigns.update', 'campaigns.launch', 'campaigns.pause', 'campaigns.budget.change',
                'content.view', 'content.create', 'content.update',
                'leads.view', 'budget.view', 'alerts.view',
                'tasks.view', 'tasks.create', 'tasks.update',
            ]],

            self::ANALYST => ['ar' => 'محلل', 'en' => 'Analyst', 'permissions' => [
                ...self::BASE, ...$read,
                'clients.view_analytics', 'reports.create', 'reports.export', 'leads.view', 'budget.view', 'alerts.view',
            ]],

            self::REPORTER => ['ar' => 'معدّ تقارير', 'en' => 'Report builder', 'permissions' => [
                ...self::BASE, ...$read,
                'clients.view_reports', 'clients.create_reports', 'clients.share_reports',
                'reports.create', 'reports.export', 'reports.share',
            ]],

            self::VIEWER => ['ar' => 'مطّلع', 'en' => 'Viewer', 'permissions' => [...self::BASE, ...$read]],
        ];
    }

    public static function isPreset(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    /**
     * The checkboxes: one section per sidebar destination, its page key and the actions inside it.
     *
     * @return list<array{key: string, ar: string, en: string, page: string, actions: list<array{key: string, ar: string, en: string}>}>
     */
    public static function sections(): array
    {
        $a = static fn (string $key, string $ar, string $en): array => ['key' => $key, 'ar' => $ar, 'en' => $en];

        return [
            ['key' => 'clients', 'ar' => 'العملاء', 'en' => 'Clients', 'page' => 'clients.view', 'actions' => [
                $a('clients.view_all', 'كل العملاء', 'Every client'),
                $a('clients.create', 'أضف عميل', 'Add a client'),
                $a('clients.update', 'عدّل العميل', 'Edit a client'),
                $a('clients.manage_team', 'فريق العميل', 'Client team'),
            ]],
            ['key' => 'campaigns', 'ar' => 'الحملات', 'en' => 'Campaigns', 'page' => 'campaigns.view', 'actions' => [
                $a('campaigns.create', 'حملة جديدة', 'New campaign'),
                $a('campaigns.update', 'عدّل', 'Edit'),
                $a('campaigns.launch', 'شغّل', 'Launch'),
                $a('campaigns.pause', 'أوقف', 'Pause'),
                $a('campaigns.budget.change', 'غيّر الميزانية', 'Change budget'),
            ]],
            ['key' => 'content', 'ar' => 'المحتوى', 'en' => 'Content', 'page' => 'content.view', 'actions' => [
                $a('content.create', 'أضف', 'Add'),
                $a('content.approve', 'اعتمد', 'Approve'),
            ]],
            ['key' => 'analytics', 'ar' => 'التحليلات', 'en' => 'Analytics', 'page' => 'analytics.view', 'actions' => []],
            ['key' => 'reports', 'ar' => 'التقارير', 'en' => 'Reports', 'page' => 'reports.view', 'actions' => [
                $a('reports.create', 'أنشئ', 'Create'),
                $a('reports.export', 'صدّر', 'Export'),
                $a('reports.share', 'شارك', 'Share'),
            ]],
            ['key' => 'leads', 'ar' => 'المهتمين', 'en' => 'Leads', 'page' => 'leads.view', 'actions' => [
                $a('leads.pii.view', 'بيانات التواصل', 'Contact details'),
                $a('leads.export', 'صدّر', 'Export'),
            ]],
            ['key' => 'finance', 'ar' => 'المالية', 'en' => 'Finance', 'page' => 'budget.view', 'actions' => [
                $a('budget.manage', 'أضف ميزانية', 'Add a budget'),
                $a('billing.view', 'الفواتير', 'Invoices'),
                $a('billing.manage', 'أصدر فاتورة', 'Issue an invoice'),
            ]],
            ['key' => 'requests', 'ar' => 'الطلبات', 'en' => 'Requests', 'page' => 'requests.view', 'actions' => [
                $a('requests.change_status', 'غيّر الحالة', 'Change status'),
                $a('requests.comment_client', 'رد على العميل', 'Reply to client'),
            ]],
            ['key' => 'alerts', 'ar' => 'التنبيهات', 'en' => 'Alerts', 'page' => 'alerts.view', 'actions' => [
                $a('alerts.manage', 'عدّل القواعد', 'Edit rules'),
            ]],
            ['key' => 'tasks', 'ar' => 'المهام', 'en' => 'Tasks', 'page' => 'tasks.view', 'actions' => [
                $a('tasks.create', 'أضف', 'Add'),
                $a('tasks.update', 'عدّل', 'Edit'),
            ]],
            ['key' => 'automations', 'ar' => 'الأتمتة', 'en' => 'Automations', 'page' => 'automations.view', 'actions' => [
                $a('automations.manage', 'عدّل', 'Edit'),
            ]],
            ['key' => 'integrations', 'ar' => 'التكاملات', 'en' => 'Integrations', 'page' => 'integrations.view', 'actions' => [
                $a('integrations.connect', 'اربط', 'Connect'),
                $a('integrations.disconnect', 'افصل', 'Disconnect'),
            ]],
            ['key' => 'settings', 'ar' => 'الإعدادات', 'en' => 'Settings', 'page' => 'settings.manage', 'actions' => [
                $a('users.view', 'اعرض الفريق', 'See the team'),
                $a('users.update', 'وزّع العملاء', 'Assign clients'),
            ]],
        ];
    }

    /** @return list<string> every key a checkbox on this screen can turn on or off */
    public static function editableKeys(): array
    {
        $keys = [];
        foreach (self::sections() as $section) {
            $keys[] = $section['page'];
            foreach ($section['actions'] as $action) {
                $keys[] = $action['key'];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The keys a preset starts with, restricted to what the catalogue actually holds — a key the
     * seeder never wrote cannot be granted, and must not be reported as granted either.
     *
     * @return list<string>
     */
    public static function defaults(string $slug): array
    {
        $preset = self::all()[$slug] ?? null;
        abort_if($preset === null, 404);

        $catalogue = Permission::query()->pluck('key')->map(fn ($k) => (string) $k)->all();

        return $preset['permissions'] === null
            ? $catalogue
            : array_values(array_intersect($preset['permissions'], $catalogue));
    }

    /**
     * The keys an actor may hand out: their own, as their ceiling actually backs them.
     *
     * `clients.view_all` counts only for somebody who really reaches every client. A membership that
     * NAMES clients outranks the permission (`ClientScopeResolver::reachableClientIds`), so a manager
     * confined to client A whose role happens to carry the key does not «hold» it — otherwise they
     * could tick «كل العملاء» on a shared role, or invite a second account of their own as the
     * workspace manager, and walk out of their own ceiling.
     *
     * @return list<string>
     */
    public static function heldBy(User $actor): array
    {
        $keys = $actor->permissionKeys()->map(fn ($k) => (string) $k)->all();

        if (! $actor->is_platform_admin && app(ClientScopeResolver::class)->reachableClientIds($actor) !== null) {
            $keys = array_diff($keys, [ClientScopeResolver::ALL_CLIENTS]);
        }

        return array_values($keys);
    }

    /**
     * This tenant's role for a preset, created with the preset's keys the first time it is needed —
     * when somebody is invited into it, moved into it, or its boxes are saved. Never on a read.
     *
     * An existing role of the same slug (an older tenant already has `analyst`) is used as it is:
     * its keys were somebody's decision and are not overwritten.
     */
    public static function ensure(string $tenantId, string $slug): Role
    {
        $existing = Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->first();
        if ($existing !== null) {
            return $existing;
        }

        $preset = self::all()[$slug] ?? null;
        abort_if($preset === null, 404);

        $role = Role::query()->create(['tenant_id' => $tenantId, 'slug' => $slug, 'name' => $preset['en'], 'is_system' => true]);
        $role->permissions()->sync(Permission::query()->whereIn('key', self::defaults($slug))->pluck('id')->all());

        return $role;
    }
}
