<?php

declare(strict_types=1);

namespace App\Domains\Access\Services;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\AccessCatalogue;
use App\Domains\Access\Support\AccessSections;
use App\Domains\Access\Support\MemberOverrideMemo;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ACCESS-CENTER-001 — the rules every grant in the access center passes, in one place.
 *
 * A category is a tenant `Role` (the seven ready-made ones from `WorkspaceRolePresets`, any role the workspace
 * already had, and the custom ones the owner creates here). An override is a key added or removed for ONE member.
 * Both are checked by the same three rules, whoever calls:
 *
 * 1. **Nobody grants what they do not hold.** Every key a grant ADDS must be among the actor's own keys as their
 *    ceiling actually backs them (`WorkspaceRolePresets::heldBy`). Removing a key narrows and needs nothing more.
 * 2. **Only the owner hands out the team or the workspace.** A category or an override that carries an
 *    `AccessCatalogue::ADMIN_KEYS` key is the owner's to give (`tenant-owner` role, or the platform operator).
 * 3. **Only the owner shapes categories.** Creating, renaming, re-scoping or deleting one changes what every
 *    member in it can do, so it is the owner's decision alone.
 *
 * Platform operators bypass 1 and 2 exactly as they already bypass `hasPermission`.
 */
final class AccessCenter
{
    public function isOwner(User $actor): bool
    {
        return (bool) $actor->is_platform_admin || $actor->hasRole(WorkspaceRolePresets::OWNER);
    }

    /**
     * @param  list<string>  $added  keys this grant switches ON
     * @param  list<string>  $resulting  the keys the person ends up with
     */
    public function assertMayGrant(User $actor, array $added, array $resulting): void
    {
        if ($actor->is_platform_admin) {
            return;
        }

        $beyond = array_values(array_diff($added, WorkspaceRolePresets::heldBy($actor)));
        abort_if($beyond !== [], 403, 'You can only grant permissions you hold yourself.');

        abort_if(AccessCatalogue::isAdminSet($resulting) && ! $this->isOwner($actor), 403, 'Only the workspace owner can hand out team or settings control.');
    }

    public function assertOwner(User $actor): void
    {
        abort_unless($this->isOwner($actor), 403, 'Only the workspace owner can change categories.');
    }

    /**
     * The keys of a category in this tenant: the stored role's, or a ready-made one's defaults before first use.
     *
     * @return list<string>|null null = no such category here
     */
    public function categoryKeys(string $tenantId, string $slug): ?array
    {
        $role = Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->first();
        if ($role !== null) {
            return $role->permissions()->pluck('key')->map(fn ($k) => (string) $k)->all();
        }

        return WorkspaceRolePresets::isPreset($slug) ? WorkspaceRolePresets::defaults($slug) : null;
    }

    /**
     * The category somebody is invited into or moved into, checked BEFORE a ready-made one is created, so a
     * refused request leaves no row behind. Used by every invitation path (settings team, app team, access center).
     */
    public function assignableRole(User $actor, string $tenantId, string $slug): Role
    {
        $keys = $this->categoryKeys($tenantId, $slug);
        if ($keys === null) {
            throw ValidationException::withMessages(['role' => 'Unknown role for this workspace.']);
        }

        $this->assertMayGrant($actor, $keys, $keys);

        return Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->first()
            ?? WorkspaceRolePresets::ensure($tenantId, $slug);
    }

    /**
     * Turn area levels chosen for ONE person into key deltas against their category.
     *
     * @param  list<string>  $categoryKeys
     * @param  array<string, string>  $levels
     * @return array{allow: list<string>, deny: list<string>, effective: list<string>}
     */
    public function overridesFor(array $categoryKeys, array $levels): array
    {
        $unknown = array_diff(array_keys($levels), AccessCatalogue::areaKeys());
        $badLevel = array_diff(array_values($levels), AccessCatalogue::LEVELS);
        if ($unknown !== [] || $badLevel !== []) {
            throw ValidationException::withMessages(['overrides' => 'Unknown area or level.']);
        }

        $effective = AccessCatalogue::applyLevels($categoryKeys, $levels);

        return [
            'allow' => array_values(array_diff($effective, $categoryKeys)),
            'deny' => array_values(array_intersect(array_diff($categoryKeys, $effective), AccessCatalogue::switchableKeys())),
            'effective' => $effective,
        ];
    }

    /**
     * A member's stored overrides in this tenant.
     *
     * @return array{allow: list<string>, deny: list<string>}
     */
    public function memberOverrides(string $tenantId, int $userId): array
    {
        $rows = DB::table('member_access_overrides')->where('tenant_id', $tenantId)->where('user_id', $userId)->get(['permission_key', 'effect']);

        return [
            'allow' => $rows->where('effect', 'allow')->pluck('permission_key')->map(fn ($k) => (string) $k)->sort()->values()->all(),
            'deny' => $rows->where('effect', 'deny')->pluck('permission_key')->map(fn ($k) => (string) $k)->sort()->values()->all(),
        ];
    }

    /**
     * Replace a member's overrides in this tenant. Only switchable keys are ever written: the base and the client
     * scope are not something an override reaches.
     *
     * @param  list<string>  $allow
     * @param  list<string>  $deny
     */
    public function writeOverrides(string $tenantId, int $userId, array $allow, array $deny, ?int $by): void
    {
        $switchable = AccessCatalogue::switchableKeys();
        // ACCESS-SECTIONS-001 — a switched-off section is a `section:<key>` deny row in the same store.
        $sectionMarkers = array_map(fn (string $s) => AccessSections::PREFIX.$s, AccessSections::keys());
        $now = now();

        DB::table('member_access_overrides')->where('tenant_id', $tenantId)->where('user_id', $userId)->delete();

        $rows = [];
        foreach ([['allow', $allow], ['deny', $deny]] as [$effect, $keys]) {
            $writable = $effect === 'deny' ? [...$switchable, ...$sectionMarkers] : $switchable;
            foreach (array_unique(array_intersect($keys, $writable)) as $key) {
                $rows[] = ['tenant_id' => $tenantId, 'user_id' => $userId, 'permission_key' => $key, 'effect' => $effect, 'set_by' => $by, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows !== []) {
            DB::table('member_access_overrides')->insert($rows);
        }
        app(MemberOverrideMemo::class)->forget();
    }

    /**
     * Every category this tenant can hand out: the ready-made ones, any role it already had, and its own.
     *
     * @return list<array<string, mixed>>
     */
    public function categories(string $tenantId): array
    {
        $presets = WorkspaceRolePresets::all();
        $stored = Role::query()->where('tenant_id', $tenantId)->with('permissions:id,key')->get()->keyBy('slug');

        $members = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('memberships', function ($j) use ($tenantId): void {
                $j->on('memberships.user_id', '=', 'role_user.user_id')->where('memberships.tenant_id', '=', $tenantId)->where('memberships.status', '=', 'active');
            })
            ->where('roles.tenant_id', $tenantId)
            ->groupBy('roles.slug')
            ->selectRaw('roles.slug as slug, count(distinct role_user.user_id) as n')
            ->pluck('n', 'slug');

        $pending = DB::table('workspace_invitations')->where('tenant_id', $tenantId)->whereNull('accepted_at')->whereNull('portal')
            ->groupBy('role_slug')->selectRaw('role_slug, count(*) as n')->pluck('n', 'role_slug');

        $out = [];
        $describe = function (string $slug, string $ar, string $en, string $kind, array $keys) use (&$out, $members, $pending): void {
            $out[] = [
                'slug' => $slug,
                'name_ar' => $ar,
                'name_en' => $en,
                'kind' => $kind, // owner | builtin | custom
                'all_clients' => in_array('clients.view_all', $keys, true),
                'admin' => AccessCatalogue::isAdminSet($keys),
                'areas' => AccessCatalogue::levelsFor($keys),
                'members' => (int) ($members[$slug] ?? 0),
                'pending' => (int) ($pending[$slug] ?? 0),
            ];
        };

        foreach (WorkspaceRolePresets::ORDER as $slug) {
            $role = $stored->get($slug);
            $keys = $role !== null ? $role->permissions->pluck('key')->map(fn ($k) => (string) $k)->all() : WorkspaceRolePresets::defaults($slug);
            $describe($slug, $presets[$slug]['ar'], $presets[$slug]['en'], $slug === WorkspaceRolePresets::OWNER ? 'owner' : 'builtin', $keys);
        }

        foreach ($stored as $slug => $role) {
            if (WorkspaceRolePresets::isPreset((string) $slug)) {
                continue;
            }
            $keys = $role->permissions->pluck('key')->map(fn ($k) => (string) $k)->all();
            // A role the tenant made keeps the name THEY typed in both languages (ROLE-LABEL-001).
            $describe((string) $slug, (string) $role->name, (string) $role->name, $role->is_system ? 'builtin' : 'custom', $keys);
        }

        return $out;
    }

    /**
     * Create a custom category from area levels (optionally starting from another category's keys).
     *
     * @param  array<string, string>  $levels
     */
    public function createCategory(User $actor, string $tenantId, string $name, ?string $from, array $levels, bool $allClients): Role
    {
        $this->assertOwner($actor);
        $this->assertNameFree($tenantId, $name, null);

        $start = $from !== null ? $this->categoryKeys($tenantId, $from) : [];
        if ($start === null) {
            throw ValidationException::withMessages(['from' => 'Unknown category.']);
        }

        $keys = $this->shape($start, $levels, $allClients);
        $this->assertMayGrant($actor, $keys, $keys);

        $role = Role::query()->create([
            'tenant_id' => $tenantId,
            'slug' => $this->freeSlug($tenantId),
            'name' => $name,
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')->all());

        return $role;
    }

    /**
     * The keys of a category after its areas and its client scope are set. The base is always present: a category
     * without it opens nothing.
     *
     * @param  list<string>  $start
     * @param  array<string, string>  $levels
     * @return list<string>
     */
    public function shape(array $start, array $levels, ?bool $allClients): array
    {
        $this->overridesFor($start, $levels); // validates names and levels
        $keys = AccessCatalogue::applyLevels($start, $levels);
        $keys = array_values(array_unique([...$keys, ...AccessCatalogue::existing(AccessCatalogue::BASE)]));

        if ($allClients === true) {
            $keys[] = 'clients.view_all';
        } elseif ($allClients === false) {
            $keys = array_values(array_diff($keys, ['clients.view_all']));
        }

        return AccessCatalogue::existing(array_values(array_unique($keys)));
    }

    public function assertNameFree(string $tenantId, string $name, ?int $exceptId): void
    {
        $taken = Role::query()->where('tenant_id', $tenantId)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
        $preset = collect(WorkspaceRolePresets::all())->contains(fn ($p) => mb_strtolower($p['ar']) === mb_strtolower(trim($name)) || mb_strtolower($p['en']) === mb_strtolower(trim($name)));

        if ($taken || $preset) {
            throw ValidationException::withMessages(['name' => 'A category with this name already exists.']);
        }
    }

    private function freeSlug(string $tenantId): string
    {
        do {
            $slug = 'cat-'.strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->exists());

        return $slug;
    }
}
