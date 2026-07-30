<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Domains\Tenancy\Services\MembershipProvisioner;
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
    /** Mirrors OnboardingController::SERVICE_MODULES — one service choice, one module set. */
    private const SERVICE_MODULES = [
        'paid_media' => ['paid_media'],
        'influencer_marketing' => ['influencer_marketing'],
        'combined' => ['paid_media', 'influencer_marketing'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly MembershipProvisioner $memberships,
    ) {}

    public function execute(RegisterData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $tenant = Tenant::create([
                'name' => $data->tenantName,
                'slug' => $this->uniqueSlug($data->tenantName),
                'status' => 'trialing',
                // Email verification always comes first. Account type and modules are recorded here when the
                // visitor already chose a path on the public site, so the wizard does not ask a second time.
                'onboarding_step' => 'verify_email',
                'subscription_plan' => 'trial',
                'account_type' => $data->accountType,
                'enabled_modules' => $data->service !== null ? self::SERVICE_MODULES[$data->service] : null,
            ]);

            // Make the new tenant the active scope so nested writes are correctly attributed.
            $this->context->setTenantId((string) $tenant->id);

            Workspace::create([
                'tenant_id' => $tenant->id,
                'name' => 'Default Workspace',
                'slug' => 'default',
            ]);

            // Created without the automatic membership so the OWNER membership below is the one
            // that lands, rather than the generic 'member' the model hook would have granted first.
            $user = User::withoutAutoMembership(fn () => User::create([
                'tenant_id' => $tenant->id,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'is_platform_admin' => false,
            ]));

            $ownerRole = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
                ['name' => 'Tenant Owner', 'is_system' => true],
            );
            // A self-registered owner must actually be able to operate their workspace — grant the full
            // permission catalogue (previously the role was created empty, leaving new owners powerless).
            $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
            $user->assignRole($ownerRole);

            // ADR 0002: the membership is what routes the user to a portal after signing in. Granted
            // through the same provisioner the seeders use, so there is one grant path rather than two
            // that can drift. Inferring a portal from the account type at every login instead would
            // make the portal a permanent property of the user, which it explicitly is not.
            $this->memberships->ensure(
                $user,
                $tenant,
                Portal::forAccountType($data->accountType),
                role: 'owner',
            );

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
