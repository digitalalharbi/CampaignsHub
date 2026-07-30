<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
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
    /** Mirrors OnboardingController::SERVICE_MODULES — one service choice, one module set. */
    private const SERVICE_MODULES = [
        'paid_media' => ['paid_media'],
        'influencer_marketing' => ['influencer_marketing'],
        'combined' => ['paid_media', 'influencer_marketing'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly GrantMembership $grants,
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

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
            ]);

            $ownerRole = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
                ['name' => 'Tenant Owner', 'is_system' => true],
            );
            // A self-registered owner must actually be able to operate their workspace — grant the full
            // permission catalogue (previously the role was created empty, leaving new owners powerless).
            $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
            $user->assignRole($ownerRole);

            /*
             * ADR 0002: access is granted explicitly, inside this same transaction, so a workspace
             * can never exist with an owner who cannot reach it — nor an owner without a workspace.
             *
             * The portal comes from the path the visitor chose on the way in, and is stated here
             * once at creation. It is NOT re-derived from the account type on later requests: that
             * would make the portal a permanent property of the person, which it is not.
             */
            $this->grants->execute(new MembershipGrant(
                user: $user,
                tenant: $tenant,
                portal: Portal::forAccountType($data->accountType),
                role: 'owner',
            ));
            // The owner reaches every client through the clients.view_all permission granted with the
            // full catalogue above — never through having no scope rows, which now means nothing.

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
