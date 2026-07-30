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

    /** @return Collection<int,string> */
    public function permissionKeys(): Collection
    {
        return $this->roles()
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
        return $this->roles->contains(fn (Role $role) => $role->slug === $slug);
    }
}
