<?php

declare(strict_types=1);

namespace App\Domains\Access\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A role groups permissions. Roles are tenant-scoped (tenant_id) or system-wide (tenant_id null).
 */
final class Role extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'name', 'slug', 'is_system'];

    protected $casts = ['is_system' => 'bool'];

    protected static function booted(): void
    {
        self::creating(function (Role $role): void {
            if (empty($role->uuid)) {
                $role->uuid = (string) Str::uuid();
            }
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function givePermissionTo(string ...$keys): void
    {
        $ids = Permission::whereIn('key', $keys)->pluck('id');
        $this->permissions()->syncWithoutDetaching($ids);
    }
}
