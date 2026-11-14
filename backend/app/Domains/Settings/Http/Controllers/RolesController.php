<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TEAM-ROLE-PRESETS-001 — «الأدوار والصلاحيات»: the ready-made roles and their checkboxes.
 *
 * ## Who may change a role, and how far
 *
 * - Only a holder of `settings.manage`, the same gate as every other team mutation.
 * - Only THIS tenant's roles. A slug is looked up under the bound tenant; another workspace's role
 *   of the same slug is never read or written, and an unknown slug is a 404.
 * - The workspace manager (`tenant-owner`) is never editable: it holds every key, and a box that
 *   could untick `settings.manage` on it would lock the workspace out of its own settings.
 * - **Nobody grants what they do not hold.** A key may be switched ON only by somebody whose own
 *   permissions include it. Switching a key OFF narrows and needs nothing more. Without this, a
 *   member who may manage settings but not see every client could tick «كل العملاء» on their own
 *   role and walk out of their ceiling.
 * - Only the keys on the screen change. Any other key the role holds (`projects.view.all`, or
 *   whatever an older tenant gave its own `analyst`) is kept exactly as it was.
 *
 * Every save is audited with the keys before and after. Reading never creates a role: a preset a
 * tenant has never used is described from its defaults, and becomes a row only when it is saved,
 * or somebody is invited into it or moved into it.
 */
final class RolesController extends Controller
{
    /** GET /api/v1/settings/roles */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenantId = $this->tenantId();
        $editable = WorkspaceRolePresets::editableKeys();
        $mine = WorkspaceRolePresets::heldBy($request->user());

        $stored = Role::query()->where('tenant_id', $tenantId)
            ->whereIn('slug', WorkspaceRolePresets::ORDER)
            ->with('permissions:id,key')
            ->get()
            ->keyBy('slug');

        $members = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('memberships', function ($j) use ($tenantId): void {
                $j->on('memberships.user_id', '=', 'role_user.user_id')
                    ->where('memberships.tenant_id', '=', $tenantId)
                    ->where('memberships.status', '=', 'active');
            })
            ->where('roles.tenant_id', $tenantId)
            ->groupBy('roles.slug')
            ->selectRaw('roles.slug as slug, count(distinct role_user.user_id) as n')
            ->pluck('n', 'slug');

        $presets = WorkspaceRolePresets::all();
        $roles = [];
        foreach (WorkspaceRolePresets::ORDER as $slug) {
            $role = $stored->get($slug);
            $keys = $role !== null
                ? $role->permissions->pluck('key')->map(fn ($k) => (string) $k)->all()
                : WorkspaceRolePresets::defaults($slug);

            $roles[] = [
                'slug' => $slug,
                'name_ar' => $presets[$slug]['ar'],
                'name_en' => $presets[$slug]['en'],
                'locked' => $slug === WorkspaceRolePresets::OWNER,
                'permissions' => array_values(array_intersect($editable, $keys)),
                'members' => (int) ($members[$slug] ?? 0),
            ];
        }

        return ApiResponse::success([
            'sections' => WorkspaceRolePresets::sections(),
            'roles' => $roles,
            // What the reader may switch ON: the boxes beyond this stay greyed, never silently refused.
            'grantable' => array_values(array_intersect($editable, $mine)),
        ], 'Roles retrieved.');
    }

    /** PUT /api/v1/settings/roles/{slug} — body: { permissions: [...] }, the boxes that are ticked. */
    public function update(Request $request, string $slug, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        abort_unless(WorkspaceRolePresets::isPreset($slug), 404);
        abort_if($slug === WorkspaceRolePresets::OWNER, 422, 'The workspace manager holds everything and cannot be edited.');

        $editable = WorkspaceRolePresets::editableKeys();
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', 'in:'.implode(',', $editable)],
        ]);
        $wanted = array_values(array_unique(array_map('strval', $data['permissions'])));

        $tenantId = $this->tenantId();

        return DB::transaction(function () use ($request, $slug, $tenantId, $editable, $wanted, $audit): JsonResponse {
            $role = WorkspaceRolePresets::ensure($tenantId, $slug);
            $before = $role->permissions()->pluck('key')->map(fn ($k) => (string) $k)->all();

            // Nobody grants what they do not hold: only ADDED keys are checked, removing one narrows.
            $added = array_values(array_diff($wanted, $before));
            $mine = WorkspaceRolePresets::heldBy($request->user());
            $beyond = $request->user()->is_platform_admin ? [] : array_values(array_diff($added, $mine));
            abort_if($beyond !== [], 403, 'You can only grant permissions you hold yourself.');

            // Keys off this screen are kept; the screen's own keys become exactly what was ticked.
            $kept = array_values(array_diff($before, $editable));
            $after = array_values(array_unique([...$kept, ...$wanted]));
            $role->permissions()->sync(Permission::query()->whereIn('key', $after)->pluck('id')->all());

            $audit->log(
                action: 'settings.roles.updated',
                entityType: 'role',
                entityId: (string) $role->uuid,
                before: ['slug' => $slug, 'permissions' => array_values(array_intersect($editable, $before))],
                after: ['slug' => $slug, 'permissions' => $wanted],
            );

            return ApiResponse::success([
                'slug' => $slug,
                'permissions' => array_values(array_intersect($editable, $after)),
            ], 'Role saved.');
        });
    }

    /** POST /api/v1/settings/roles/{slug}/reset — the preset's own boxes again. */
    public function reset(Request $request, string $slug, AuditLogger $audit): JsonResponse
    {
        abort_unless(WorkspaceRolePresets::isPreset($slug), 404);
        $editable = WorkspaceRolePresets::editableKeys();
        $request->merge(['permissions' => array_values(array_intersect($editable, WorkspaceRolePresets::defaults($slug)))]);

        return $this->update($request, $slug, $audit);
    }

    private function tenantId(): string
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return $id;
    }
}
