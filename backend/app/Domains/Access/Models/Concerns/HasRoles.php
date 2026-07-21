<?php

declare(strict_types=1);

namespace App\Domains\Access\Models\Concerns;

use App\Domains\Access\Models\Role;
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

    public function assignRole(Role|string $role): void
    {
        $model = $role instanceof Role
            ? $role
            : Role::where('slug', $role)->where(function ($q) {
                $q->where('tenant_id', $this->tenant_id)->orWhereNull('tenant_id');
            })->firstOrFail();

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
