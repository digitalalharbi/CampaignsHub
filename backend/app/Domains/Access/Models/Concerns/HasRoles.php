<?php

declare(strict_types=1);

namespace App\Domains\Access\Models\Concerns;

use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Adds role/permission helpers to the User model. Authorization is always evaluated server-side.
 */
trait HasRoles
{
    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Assign a role.
     *
     * A slug is resolved against the CURRENT tenant, not `users.tenant_id` (ADR 0002). That column
     * describes at most one workspace, and a user may hold memberships in several — resolving
     * through it picked whichever tenant happened to be stamped on the row at registration, which
     * for anyone with two memberships is a coin flip. The request's tenant context is the tenant
     * this call is actually happening in.
     *
     * A global role (`tenant_id IS NULL`) matches regardless, and is the fallback when no tenant is
     * bound — a slug that resolves to nothing still throws rather than silently assigning nothing.
     */
    public function assignRole(Role|string $role): void
    {
        $model = $role instanceof Role
            ? $role
            : Role::query()->where('slug', $role)
                ->where(function ($q) {
                    $current = app(TenantContext::class)->tenantId();

                    if ($current !== null) {
                        $q->where('tenant_id', $current)->orWhereNull('tenant_id');

                        return;
                    }

                    $q->whereNull('tenant_id');
                })
                ->firstOrFail();

        $this->roles()->syncWithoutDetaching([$model->id]);
    }

    /**
     * The roles that count in the tenant this request is for.
     *
     * A person may hold roles in several tenants (an owner of two agencies, a freelancer on two
     * rosters). Reading every role the user has made their permissions the UNION of all of them, so
     * a limited role in one agency inherited the full owner role of another the moment both were
     * held. When a tenant is bound — which every authenticated API request does — only that tenant's
     * roles and the global (tenant-less) ones count. With none bound (the sign-in payload, before a
     * membership is chosen) all roles are listed: that answer is only ever DISPLAYED, never
     * authorised against, because every request that decides anything has a tenant.
     *
     * @return Collection<int,Role>
     */
    public function rolesInCurrentTenant(): Collection
    {
        return $this->currentTenantRolesQuery()->get();
    }

    /** @return BelongsToMany<Role, $this> */
    private function currentTenantRolesQuery(): BelongsToMany
    {
        $current = app(TenantContext::class)->tenantId();

        $query = $this->roles();
        if ($current !== null) {
            $query->where(fn ($w) => $w->whereNull('roles.tenant_id')->orWhere('roles.tenant_id', $current));
        }

        return $query;
    }

    /** @return Collection<int,string> */
    public function permissionKeys(): Collection
    {
        return $this->currentTenantRolesQuery()
            ->with('permissions:id,key')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('key'))
            ->unique()
            ->values();
    }

    public function hasPermission(string $key): bool
    {
        if ($this->is_platform_admin) {
            return true; // platform admins bypass tenant permission checks
        }

        return $this->permissionKeys()->contains($key);
    }

    public function hasRole(string $slug): bool
    {
        return $this->rolesInCurrentTenant()->contains(fn (Role $role) => $role->slug === $slug);
    }
}
