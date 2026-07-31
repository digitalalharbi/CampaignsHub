<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Actions;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The crossing: an application becomes a workspace (SIGNUP-002).
 *
 * Everything the old `RegisterTenantAction` did at the moment someone pressed "create account" now
 * happens HERE, and only when the account has actually reached Active. That is the difference the
 * whole unit is about — before, submitting a form produced a tenant, a workspace, a membership and
 * portal access, so there was no such thing as an application that had not been granted anything.
 *
 * It refuses to run early, and that refusal is the enforcement. A caller that has not verified,
 * approved and taken payment cannot get a workspace out of this by asking nicely.
 */
final class ProvisionWorkspace
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GrantMembership $grants,
        private readonly TransitionAccountState $transitions,
    ) {}

    /**
     * @return array{tenant: Tenant, user: User}
     */
    public function execute(RegistrationRequest $request): array
    {
        /*
         * The gate, stated once.
         *
         * Not `$request->state === Active`: this runs as PART of reaching Active, so the caller has
         * satisfied the conditions and is about to record it. What must be true is that every
         * condition the path imposes has been met — anything else and we would be creating a
         * workspace for an unverified, unapproved or unpaid application.
         */
        if ($request->isProvisioned()) {
            // Idempotent: a webhook delivered twice must not mint a second workspace.
            return ['tenant' => $request->tenant, 'user' => User::where('email', $request->email)->firstOrFail()];
        }

        if (! $request->emailIsVerified()) {
            throw new RuntimeException('A workspace cannot be created before the email address is verified.');
        }

        if (! in_array($request->state, [AccountState::ApprovedAwaitingPayment, AccountState::PaymentPending], true)) {
            throw new RuntimeException(
                "A workspace cannot be created from state {$request->state->value} — the account has not been approved."
            );
        }

        return DB::transaction(function () use ($request): array {
            $tenant = Tenant::create([
                'name' => $request->tenant_name,
                'slug' => $this->uniqueSlug($request->tenant_name),
                // Created inactive. `provision()` below moves it to Active with a reason, so the
                // audit trail records the crossing rather than a workspace that was simply born
                // switched on.
                'status' => 'inactive',
                'account_type' => $request->account_type,
                'subscription_plan' => $request->plan_code,
                'onboarding_step' => 'workspace',
                'enabled_modules' => $request->service !== null ? [$request->service] : null,
            ]);

            $this->context->setTenantId((string) $tenant->id);

            Workspace::create([
                'tenant_id' => $tenant->id,
                'name' => 'Default Workspace',
                'slug' => 'default',
            ]);

            // The password was captured at application time and hashed then — this is the same
            // credential the applicant chose, not a new one they have to be told about.
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'phone' => $request->phone,
            ]);

            // forceFill, because `email_verified_at` is not mass-assignable — marking an address
            // verified is not something a payload may do. It was verified during the gated path, and
            // asking the customer to prove it again after they have paid would be absurd.
            $user->forceFill(['email_verified_at' => $request->email_verified_at])->save();

            $ownerRole = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
                ['name' => 'Tenant Owner', 'is_system' => true],
            );
            $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
            $user->assignRole($ownerRole);

            /*
             * The membership — the thing that did NOT exist while the application was pending.
             *
             * The portal comes from what the applicant asked for, honoured only because they have
             * now been approved for it. `forAccountType` is the fallback for an application that
             * named no portal.
             */
            $portal = Portal::tryFrom((string) $request->requested_portal)
                ?? Portal::forAccountType($request->account_type);

            $this->grants->execute(new MembershipGrant(
                user: $user, tenant: $tenant, portal: $portal, role: 'owner',
            ));

            $this->transitions->provision(
                $tenant,
                AccountState::Active,
                'Provisioned from registration request '.$request->getKey().' after verification and approval.',
            );

            $request->forceFill([
                'tenant_id' => $tenant->id,
                'provisioned_at' => now(),
                'state' => AccountState::Active->value,
                'state_changed_at' => now(),
            ])->save();

            return ['tenant' => $tenant->refresh(), 'user' => $user];
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
