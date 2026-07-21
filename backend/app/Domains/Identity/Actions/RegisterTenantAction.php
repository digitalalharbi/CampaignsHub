<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Access\Models\Role;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Self-serve signup: provisions a new tenant, its first workspace, and the owner user, then
 * assigns the tenant-owner role. Everything runs in a single transaction.
 */
final class RegisterTenantAction
{
    public function __construct(private readonly TenantContext $context) {}

    public function execute(RegisterData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $tenant = Tenant::create([
                'name' => $data->tenantName,
                'slug' => $this->uniqueSlug($data->tenantName),
                'status' => 'trialing',
            ]);

            // Make the new tenant the active scope so nested writes are correctly attributed.
            $this->context->setTenantId((string) $tenant->id);

            Workspace::create([
                'tenant_id' => $tenant->id,
                'name' => 'Default Workspace',
                'slug' => 'default',
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'is_platform_admin' => false,
            ]);

            $ownerRole = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
                ['name' => 'Tenant Owner', 'is_system' => true],
            );
            $user->assignRole($ownerRole);

            return $user;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $i = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
